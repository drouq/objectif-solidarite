/*
 * Admin scripts: block editor (Quill rich text, card grids, videos), media picker,
 * media fields, unsaved-changes warning, character counters, confirmations.
 * Built with esbuild into public/assets/admin/admin.js (no CDN, no API key).
 */
import Quill from 'quill';

const Delta = Quill.import('delta');

// ---------------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------------

function el(tag, attrs, children) {
  const node = document.createElement(tag);
  if (attrs) {
    Object.keys(attrs).forEach((key) => {
      const value = attrs[key];
      if (value === null || value === undefined || value === false) return;
      if (key === 'text') node.textContent = value;
      else if (key === 'html') node.innerHTML = value;
      else if (key.startsWith('on')) node.addEventListener(key.slice(2), value);
      else node.setAttribute(key, value === true ? '' : value);
    });
  }
  (children || []).forEach((child) => {
    if (child) node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
  });
  return node;
}

function csrfToken() {
  const meta = document.querySelector('meta[name="csrf-token"]');
  return meta ? meta.getAttribute('content') : '';
}

let dirty = false;
function markDirty() { dirty = true; }

let uid = 0;
function nextId(prefix) { uid += 1; return prefix + '-' + uid; }

// Live region for short announcements to screen readers.
let liveRegion = null;
function announce(message) {
  if (!liveRegion) {
    liveRegion = el('div', { class: 'visually-hidden', 'aria-live': 'polite', role: 'status' });
    document.body.appendChild(liveRegion);
  }
  liveRegion.textContent = '';
  window.setTimeout(() => { liveRegion.textContent = message; }, 50);
}

// ---------------------------------------------------------------------------
// Media store and picker
// ---------------------------------------------------------------------------

const mediaStore = {};
(function loadInitialMedia() {
  const data = document.getElementById('media-data');
  if (!data) return;
  try {
    const parsed = JSON.parse(data.textContent || '{}');
    Object.keys(parsed).forEach((id) => { mediaStore[id] = parsed[id]; });
  } catch (e) { /* ignore */ }
})();

let mediaList = null;
async function fetchMediaList(force) {
  if (mediaList && !force) return mediaList;
  const response = await fetch('/admin/medias.json', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
  if (!response.ok) throw new Error('Impossible de charger la médiathèque.');
  const data = await response.json();
  mediaList = data.media;
  mediaList.forEach((m) => { mediaStore[m.id] = m; });
  return mediaList;
}

async function uploadMedia(file, alt) {
  const form = new FormData();
  form.append('csrf', csrfToken());
  form.append('file', file);
  form.append('alt', alt || '');
  const response = await fetch('/admin/medias?format=json', {
    method: 'POST',
    body: form,
    credentials: 'same-origin',
    headers: { Accept: 'application/json', 'X-CSRF-Token': csrfToken() },
  });
  let data = {};
  try { data = await response.json(); } catch (e) { /* ignore */ }
  if (!response.ok) throw new Error(data.error || 'L\'envoi a échoué.');
  mediaStore[data.media.id] = data.media;
  mediaList = null;
  return data.media;
}

let picker = null;

function buildPicker() {
  const title = el('h2', { id: 'picker-title', text: 'Choisir une image' });
  const status = el('p', { class: 'picker-status', role: 'status' });
  const grid = el('ul', { class: 'picker-grid', role: 'list' });
  const fileInput = el('input', { type: 'file', id: 'picker-file', accept: 'image/jpeg,image/png,image/webp' });
  const altInput = el('input', { type: 'text', id: 'picker-alt', maxlength: '300' });
  const uploadButton = el('button', { type: 'button', class: 'btn btn-secondary', text: 'Envoyer et choisir' });
  const closeButton = el('button', { type: 'button', class: 'picker-close', 'aria-label': 'Fermer', text: '×' });
  const dialog = el('dialog', { class: 'picker', 'aria-labelledby': 'picker-title' }, [
    el('div', { class: 'picker-head' }, [title, closeButton]),
    el('div', { class: 'picker-upload' }, [
      el('div', { class: 'field' }, [el('label', { for: 'picker-file', text: 'Envoyer un nouveau fichier' }), fileInput]),
      el('div', { class: 'field picker-alt-field' }, [el('label', { for: 'picker-alt', text: 'Description de l\'image' }), altInput]),
      uploadButton,
    ]),
    status,
    grid,
  ]);
  document.body.appendChild(dialog);

  const state = { kind: 'image', onSelect: null, opener: null };

  function close() {
    dialog.close();
    if (state.opener) state.opener.focus();
  }
  closeButton.addEventListener('click', close);
  dialog.addEventListener('cancel', (event) => { event.preventDefault(); close(); });

  function choose(media) {
    const callback = state.onSelect;
    close();
    if (callback) callback(media);
  }

  async function refresh(force) {
    status.textContent = 'Chargement…';
    grid.innerHTML = '';
    try {
      const list = (await fetchMediaList(force)).filter((m) => m.kind === state.kind);
      status.textContent = list.length ? list.length + ' fichier(s). Cliquez sur un fichier pour le choisir.' : 'Aucun fichier de ce type. Envoyez-en un ci-dessus.';
      list.forEach((m) => {
        const label = m.name || 'Sans nom';
        const button = el('button', { type: 'button', class: 'picker-item', onclick: () => choose(m) }, [
          m.kind === 'image'
            ? el('img', { src: m.thumb, alt: '', loading: 'lazy', width: '160', height: '110' })
            : el('span', { class: 'media-pdf', 'aria-hidden': 'true', text: 'PDF' }),
          el('span', { class: 'picker-name', text: label }),
        ]);
        grid.appendChild(el('li', null, [button]));
      });
    } catch (error) {
      status.textContent = error.message;
    }
  }

  uploadButton.addEventListener('click', async () => {
    const file = fileInput.files && fileInput.files[0];
    if (!file) { status.textContent = 'Choisissez d\'abord un fichier.'; fileInput.focus(); return; }
    uploadButton.disabled = true;
    status.textContent = 'Envoi en cours… (les grandes photos peuvent prendre quelques secondes)';
    try {
      const media = await uploadMedia(file, altInput.value);
      fileInput.value = '';
      altInput.value = '';
      choose(media);
    } catch (error) {
      status.textContent = error.message;
    } finally {
      uploadButton.disabled = false;
    }
  });

  return {
    open(kind, onSelect, opener) {
      state.kind = kind;
      state.onSelect = onSelect;
      state.opener = opener || document.activeElement;
      title.textContent = kind === 'pdf' ? 'Choisir un PDF' : 'Choisir une image';
      fileInput.setAttribute('accept', kind === 'pdf' ? 'application/pdf' : 'image/jpeg,image/png,image/webp');
      dialog.querySelector('.picker-alt-field').hidden = kind === 'pdf';
      dialog.showModal();
      refresh(false);
      closeButton.focus();
    },
  };
}

function openPicker(kind, onSelect, opener) {
  if (!picker) picker = buildPicker();
  picker.open(kind, onSelect, opener);
}

// ---------------------------------------------------------------------------
// Media field (one image chosen from the library)
// ---------------------------------------------------------------------------

function createMediaField(container, input, label) {
  const preview = el('div', { class: 'media-field-preview' });
  const chooseButton = el('button', { type: 'button', class: 'btn btn-secondary btn-small' });
  const clearButton = el('button', { type: 'button', class: 'button-link danger-link', text: 'Retirer' });
  const tools = el('div', { class: 'media-field-tools' }, [chooseButton, clearButton]);
  container.appendChild(preview);
  container.appendChild(tools);

  function render() {
    const id = input.value;
    const media = id ? mediaStore[id] : null;
    preview.innerHTML = '';
    if (media) {
      preview.appendChild(el('img', { src: media.thumb, alt: '', width: '160', height: '110' }));
      preview.appendChild(el('span', { class: 'media-field-name', text: media.name || 'Image' }));
      chooseButton.textContent = 'Changer';
      chooseButton.setAttribute('aria-label', 'Changer ' + label);
      clearButton.hidden = false;
      clearButton.setAttribute('aria-label', 'Retirer ' + label);
    } else {
      preview.appendChild(el('span', { class: 'media-field-empty', text: id ? 'Image introuvable' : 'Aucune image' }));
      chooseButton.textContent = 'Choisir une image';
      chooseButton.setAttribute('aria-label', 'Choisir ' + label);
      clearButton.hidden = !id;
    }
  }
  chooseButton.addEventListener('click', () => {
    openPicker('image', (media) => { input.value = String(media.id); render(); markDirty(); announce('Image choisie : ' + (media.name || '')); }, chooseButton);
  });
  clearButton.addEventListener('click', () => { input.value = ''; render(); markDirty(); chooseButton.focus(); announce('Image retirée'); });
  render();
}

function initMediaFields(scope) {
  scope.querySelectorAll('[data-media-field]').forEach((container) => {
    if (container.dataset.ready) return;
    container.dataset.ready = '1';
    const input = container.querySelector('input[type="hidden"]');
    createMediaField(container, input, container.dataset.label || 'l\'image');
  });
}

// ---------------------------------------------------------------------------
// Quill setup: French toolbar, button-style links, library images, clean paste
// ---------------------------------------------------------------------------

const Link = Quill.import('formats/link');
class ButtonLink extends Link {
  static create(value) {
    const node = super.create(value);
    node.removeAttribute('target');
    node.removeAttribute('rel');
    node.setAttribute('class', 'btn');
    return node;
  }
}
ButtonLink.blotName = 'btnlink';
ButtonLink.className = 'btn';
ButtonLink.tagName = 'A';
Quill.register(ButtonLink);

const BaseImage = Quill.import('formats/image');
class MediaImage extends BaseImage {
  static create(value) {
    const src = typeof value === 'string' ? value : value.src;
    const node = super.create(src);
    if (value && typeof value === 'object') {
      if (value.id) node.setAttribute('data-media-id', String(value.id));
      node.setAttribute('alt', value.alt || '');
    }
    return node;
  }

  static value(node) {
    return {
      src: node.getAttribute('src'),
      id: node.getAttribute('data-media-id') || '',
      alt: node.getAttribute('alt') || '',
    };
  }
}
MediaImage.blotName = 'image';
MediaImage.tagName = 'IMG';
Quill.register(MediaImage, true);

const icons = Quill.import('ui/icons');
icons.btnlink = '<svg viewBox="0 0 18 18" aria-hidden="true"><rect x="1.5" y="5" width="15" height="8" rx="2" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M5 9h8" stroke="currentColor" stroke-width="1.5"/></svg>';
icons.pdf = '<svg viewBox="0 0 18 18" aria-hidden="true"><path d="M4 1.5h7l3 3v12H4z" fill="none" stroke="currentColor" stroke-width="1.4"/><text x="9" y="13" font-size="5" text-anchor="middle" fill="currentColor" font-family="sans-serif" font-weight="700">PDF</text></svg>';
icons.undo = '<svg viewBox="0 0 18 18" aria-hidden="true"><path d="M6 4 2.5 7.5 6 11" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M3 7.5h7.5a4 4 0 0 1 0 8H8" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>';
icons.redo = '<svg viewBox="0 0 18 18" aria-hidden="true"><path d="m12 4 3.5 3.5L12 11" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M15 7.5H7.5a4 4 0 0 0 0 8H10" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>';

const TOOLBAR_LABELS = {
  'ql-bold': 'Gras',
  'ql-italic': 'Italique',
  'ql-link': 'Lien',
  'ql-btnlink': 'Lien en forme de bouton',
  'ql-blockquote': 'Citation',
  'ql-image': 'Image de la médiathèque',
  'ql-pdf': 'Lien vers un PDF de la médiathèque',
  'ql-undo': 'Annuler',
  'ql-redo': 'Rétablir',
};

function askUrl(message, current) {
  const value = window.prompt(message, current || 'https://');
  if (value === null) return null;
  const url = value.trim();
  if (url === '' || url === 'https://') return '';
  if (/^(https?:\/\/|mailto:|tel:|\/|#)/i.test(url)) return url;
  if (/^[\w.-]+@[\w.-]+\.\w+$/.test(url)) return 'mailto:' + url;
  if (/^[\w-]+(\.[\w-]+)+/.test(url)) return 'https://' + url;
  window.alert('Adresse non valide. Elle doit commencer par https:// (site externe) ou / (page de ce site).');
  return null;
}

function createRichText(container, html) {
  const toolbarId = nextId('toolbar');
  const editorHost = el('div', { class: 'rich-text' });
  container.appendChild(editorHost);
  const quill = new Quill(editorHost, {
    theme: 'snow',
    formats: ['header', 'bold', 'italic', 'link', 'btnlink', 'list', 'blockquote', 'image'],
    placeholder: 'Écrivez votre texte ici…',
    modules: {
      history: { delay: 800, maxStack: 200, userOnly: true },
      toolbar: {
        container: [
          [{ header: [2, 3, false] }],
          ['bold', 'italic'],
          ['link', 'btnlink'],
          [{ list: 'ordered' }, { list: 'bullet' }],
          ['blockquote', 'image', 'pdf'],
          ['undo', 'redo'],
        ],
        handlers: {
          link(value) {
            if (!value) { this.quill.format('link', false); return; }
            const range = this.quill.getSelection(true);
            const url = askUrl('Adresse du lien (https://… pour un autre site, /adresse pour une page de ce site) :');
            if (!url) return;
            if (range.length === 0) {
              this.quill.insertText(range.index, url, { link: url }, 'user');
            } else {
              this.quill.format('btnlink', false);
              this.quill.format('link', url, 'user');
            }
          },
          btnlink() {
            const range = this.quill.getSelection(true);
            const formats = this.quill.getFormat(range);
            if (formats.btnlink) { this.quill.format('btnlink', false, 'user'); return; }
            const url = askUrl('Adresse du bouton (par exemple https://www.helloasso.com/…) :');
            if (!url) return;
            if (range.length === 0) {
              const label = window.prompt('Texte du bouton :', 'En savoir plus');
              if (!label) return;
              this.quill.insertText(range.index, label, { btnlink: url }, 'user');
            } else {
              this.quill.format('link', false);
              this.quill.format('btnlink', url, 'user');
            }
          },
          image() {
            const range = this.quill.getSelection(true);
            const opener = document.activeElement;
            openPicker('image', (media) => {
              this.quill.insertEmbed(range.index, 'image', { src: media.url, id: media.id, alt: media.alt || '' }, 'user');
              this.quill.setSelection(range.index + 1, 0, 'silent');
            }, opener);
          },
          pdf() {
            const range = this.quill.getSelection(true);
            const opener = document.activeElement;
            openPicker('pdf', (media) => {
              const url = media.url.replace(/\?v=\d+$/, '');
              if (range.length === 0) {
                this.quill.insertText(range.index, media.name || 'Document PDF', { link: url }, 'user');
              } else {
                this.quill.format('link', url, 'user');
              }
            }, opener);
          },
          undo() { this.quill.history.undo(); },
          redo() { this.quill.history.redo(); },
        },
      },
    },
  });

  // Paste from Word / Google Docs: Quill keeps only the allowed formats above.
  // Images that are not from the media library are dropped.
  ['SCRIPT', 'STYLE', 'TEMPLATE', 'NOSCRIPT', 'TITLE', 'META'].forEach((tag) => {
    quill.clipboard.addMatcher(tag, () => new Delta());
  });
  quill.clipboard.addMatcher('IMG', (node, delta) => {
    const src = node.getAttribute('src') || '';
    const local = src.indexOf('/media/') === 0 || src.indexOf(window.location.origin + '/media/') === 0;
    return local ? delta : new Delta();
  });

  const toolbar = quill.getModule('toolbar').container;
  toolbar.id = toolbarId;
  toolbar.setAttribute('role', 'toolbar');
  toolbar.setAttribute('aria-label', 'Mise en forme du texte');
  toolbar.querySelectorAll('button').forEach((button) => {
    let label = null;
    Object.keys(TOOLBAR_LABELS).forEach((cls) => { if (button.classList.contains(cls)) label = TOOLBAR_LABELS[cls]; });
    if (button.classList.contains('ql-list')) label = button.value === 'ordered' ? 'Liste numérotée' : 'Liste à puces';
    if (label) { button.setAttribute('aria-label', label); button.setAttribute('title', label); }
  });
  const headerPicker = toolbar.querySelector('.ql-header .ql-picker-label');
  if (headerPicker) { headerPicker.setAttribute('aria-label', 'Style du paragraphe'); headerPicker.setAttribute('title', 'Titre ou paragraphe'); }

  quill.root.setAttribute('aria-label', 'Texte du bloc');
  quill.root.setAttribute('aria-multiline', 'true');
  quill.root.setAttribute('role', 'textbox');

  if (html) {
    quill.setContents(quill.clipboard.convert({ html: html }), 'silent');
  }
  quill.history.clear();
  quill.on('text-change', markDirty);

  return {
    getHtml() {
      if (quill.getText().trim() === '' && !quill.root.querySelector('img')) return '';
      // getSemanticHTML gives real <ul>/<ol> lists. Turn &nbsp; back into normal spaces
      // except where French typography needs a non-breaking one (before : ; ! ? » and after «).
      let out = quill.getSemanticHTML();
      out = out.replace(/&nbsp;(?=[:;!?»])/g, ' ').replace(/(«)&nbsp;/g, '$1 ').replace(/&nbsp;/g, ' ');
      return out;
    },
    isEmpty() { return quill.getText().trim() === '' && !quill.root.querySelector('img'); },
    focus() { quill.focus(); },
  };
}

// ---------------------------------------------------------------------------
// Blocks
// ---------------------------------------------------------------------------

const BLOCK_NAMES = { text: 'Texte', cards: 'Grille de cartes', embed: 'Vidéo' };

function videoInfo(url) {
  const value = (url || '').trim();
  if (value === '') return null;
  if (/(youtube\.com\/(watch\?(.*&)?v=|embed\/|shorts\/|live\/)|youtu\.be\/|youtube-nocookie\.com\/embed\/)[\w-]{11}/i.test(value)) return 'YouTube';
  if (/vimeo\.com\/(video\/|channels\/[\w-]+\/|groups\/[\w-]+\/videos\/)?\d{5,}/i.test(value)) return 'Vimeo';
  return false;
}

function createCardsBody(body, data) {
  const list = el('ol', { class: 'cards-editor', role: 'list' });
  const addButton = el('button', { type: 'button', class: 'btn btn-secondary btn-small', text: '+ Ajouter une carte' });
  body.appendChild(list);
  body.appendChild(addButton);
  const cards = [];

  function renumber() {
    cards.forEach((card, index) => {
      card.number.textContent = 'Carte ' + (index + 1);
      card.up.disabled = index === 0;
      card.down.disabled = index === cards.length - 1;
      card.up.setAttribute('aria-label', 'Monter la carte ' + (index + 1));
      card.down.setAttribute('aria-label', 'Descendre la carte ' + (index + 1));
      card.remove.setAttribute('aria-label', 'Supprimer la carte ' + (index + 1));
    });
  }

  function addCard(cardData, focus) {
    const id = nextId('card');
    const mediaInput = el('input', { type: 'hidden', value: cardData.media_id ? String(cardData.media_id) : '' });
    const mediaBox = el('div', { class: 'media-field' }, [mediaInput]);
    const title = el('input', { type: 'text', id: id + '-title', maxlength: '200', value: cardData.title || '', oninput: markDirty });
    const text = el('textarea', { id: id + '-text', rows: '3', maxlength: '600', oninput: markDirty });
    text.value = cardData.text || '';
    const url = el('input', { type: 'text', id: id + '-url', maxlength: '500', value: cardData.url || '', placeholder: 'https://… ou /adresse-de-page', oninput: markDirty });
    const number = el('span', { class: 'card-number' });
    const up = el('button', { type: 'button', class: 'icon-button', text: '↑', title: 'Monter' });
    const down = el('button', { type: 'button', class: 'icon-button', text: '↓', title: 'Descendre' });
    const remove = el('button', { type: 'button', class: 'icon-button danger', text: '×', title: 'Supprimer la carte' });
    const item = el('li', { class: 'card-row' }, [
      el('div', { class: 'card-row-head' }, [number, el('span', { class: 'card-row-tools' }, [up, down, remove])]),
      el('div', { class: 'card-row-body' }, [
        mediaBox,
        el('div', { class: 'card-row-fields' }, [
          el('div', { class: 'field' }, [el('label', { for: id + '-title', text: 'Titre' }), title]),
          el('div', { class: 'field' }, [el('label', { for: id + '-text' }, ['Texte court ', el('span', { class: 'help-inline', text: '(facultatif)' })]), text]),
          el('div', { class: 'field' }, [el('label', { for: id + '-url' }, ['Lien ', el('span', { class: 'help-inline', text: '(facultatif)' })]), url]),
        ]),
      ]),
    ]);
    const card = {
      item, number, up, down, remove,
      getData() {
        return { media_id: mediaInput.value ? parseInt(mediaInput.value, 10) : null, title: title.value.trim(), text: text.value.trim(), url: url.value.trim() };
      },
    };
    up.addEventListener('click', () => moveCard(card, -1, up));
    down.addEventListener('click', () => moveCard(card, 1, down));
    remove.addEventListener('click', () => {
      const d = card.getData();
      if ((d.title || d.text || d.media_id) && !window.confirm('Supprimer cette carte ?')) return;
      const index = cards.indexOf(card);
      cards.splice(index, 1);
      item.remove();
      renumber();
      markDirty();
      announce('Carte supprimée');
      const next = cards[index] || cards[index - 1];
      (next ? next.remove : addButton).focus();
    });
    cards.push(card);
    list.appendChild(item);
    createMediaField(mediaBox, mediaInput, 'l\'image de la carte');
    renumber();
    if (focus) title.focus();
  }

  function moveCard(card, delta, button) {
    const index = cards.indexOf(card);
    const target = index + delta;
    if (target < 0 || target >= cards.length) return;
    cards.splice(index, 1);
    cards.splice(target, 0, card);
    list.innerHTML = '';
    cards.forEach((c) => list.appendChild(c.item));
    renumber();
    markDirty();
    announce('Carte déplacée en position ' + (target + 1));
    if (!button.disabled) button.focus(); else (delta < 0 ? card.down : card.up).focus();
  }

  addButton.addEventListener('click', () => { addCard({}, true); markDirty(); });
  const initial = Array.isArray(data.cards) ? data.cards : [];
  initial.forEach((c) => addCard(c, false));
  if (initial.length === 0) addCard({}, false);

  return {
    getData() { return { type: 'cards', cards: cards.map((c) => c.getData()) }; },
    isEmpty() { return cards.every((c) => { const d = c.getData(); return !d.title && !d.text && !d.media_id; }); },
    focus() { if (cards[0]) cards[0].item.querySelector('input[type="text"]').focus(); },
  };
}

function createEmbedBody(body, data) {
  const id = nextId('embed');
  const input = el('input', { type: 'url', id: id, maxlength: '500', value: data.url || '', placeholder: 'https://www.youtube.com/watch?v=…', 'aria-describedby': id + '-help' });
  const help = el('p', { class: 'help', id: id + '-help' });
  function check() {
    const info = videoInfo(input.value);
    help.classList.remove('field-error', 'ok-text');
    if (info === null) help.textContent = 'Collez l\'adresse d\'une vidéo YouTube ou Vimeo. Les autres sites ne sont pas acceptés.';
    else if (info === false) { help.textContent = 'Adresse non reconnue : seules les vidéos YouTube et Vimeo sont acceptées.'; help.classList.add('field-error'); }
    else { help.textContent = 'Vidéo ' + info + ' reconnue. Elle ne se chargera que si le visiteur clique dessus.'; help.classList.add('ok-text'); }
  }
  input.addEventListener('input', () => { check(); markDirty(); });
  body.appendChild(el('div', { class: 'field' }, [el('label', { for: id, text: 'Adresse de la vidéo' }), input, help]));
  check();
  return {
    getData() { return { type: 'embed', url: input.value.trim() }; },
    isEmpty() { return input.value.trim() === ''; },
    focus() { input.focus(); },
  };
}

function initBlockEditor(root) {
  const dataNode = document.getElementById('blocks-data');
  let initial = [];
  try { initial = JSON.parse(dataNode.textContent || '[]'); } catch (e) { initial = []; }
  if (!Array.isArray(initial)) initial = [];

  const list = el('div', { class: 'blocks-list' });
  const addBar = el('div', { class: 'add-block', role: 'group', 'aria-label': 'Ajouter un bloc' }, [
    el('span', { class: 'add-block-label', text: 'Ajouter un bloc :' }),
  ]);
  root.appendChild(list);
  root.appendChild(addBar);
  const blocks = [];

  function renumber() {
    blocks.forEach((block, index) => {
      const name = BLOCK_NAMES[block.type];
      block.title.textContent = (index + 1) + '. ' + name;
      block.section.setAttribute('aria-label', 'Bloc ' + (index + 1) + ' : ' + name);
      block.up.disabled = index === 0;
      block.down.disabled = index === blocks.length - 1;
      block.up.setAttribute('aria-label', 'Monter le bloc ' + (index + 1));
      block.down.setAttribute('aria-label', 'Descendre le bloc ' + (index + 1));
      block.remove.setAttribute('aria-label', 'Supprimer le bloc ' + (index + 1));
    });
  }

  function addBlock(data, focus) {
    const type = data.type;
    if (!BLOCK_NAMES[type]) return;
    const title = el('h3', { class: 'block-title' });
    const up = el('button', { type: 'button', class: 'icon-button', text: '↑', title: 'Monter' });
    const down = el('button', { type: 'button', class: 'icon-button', text: '↓', title: 'Descendre' });
    const remove = el('button', { type: 'button', class: 'icon-button danger', text: '×', title: 'Supprimer le bloc' });
    const body = el('div', { class: 'block-body' });
    const section = el('section', { class: 'block-item block-' + type }, [
      el('div', { class: 'block-head' }, [title, el('div', { class: 'block-tools' }, [up, down, remove])]),
      body,
    ]);
    list.appendChild(section);
    let content;
    if (type === 'text') content = createRichText(body, data.html || '');
    else if (type === 'cards') content = createCardsBody(body, data);
    else content = createEmbedBody(body, data);
    const block = { type, section, title, up, down, remove, content };
    up.addEventListener('click', () => moveBlock(block, -1, up));
    down.addEventListener('click', () => moveBlock(block, 1, down));
    remove.addEventListener('click', () => {
      if (!content.isEmpty() && !window.confirm('Supprimer ce bloc et son contenu ?')) return;
      const index = blocks.indexOf(block);
      blocks.splice(index, 1);
      section.remove();
      renumber();
      markDirty();
      announce('Bloc supprimé');
      const next = blocks[index] || blocks[index - 1];
      (next ? next.remove : addBar.querySelector('button')).focus();
    });
    blocks.push(block);
    renumber();
    if (focus) { section.scrollIntoView({ block: 'center' }); content.focus(); }
  }

  function moveBlock(block, delta, button) {
    const index = blocks.indexOf(block);
    const target = index + delta;
    if (target < 0 || target >= blocks.length) return;
    blocks.splice(index, 1);
    blocks.splice(target, 0, block);
    // Move the element without re-creating editors.
    if (delta < 0) list.insertBefore(block.section, blocks[target + 1].section);
    else list.insertBefore(block.section, blocks[target - 1].section.nextSibling);
    renumber();
    markDirty();
    announce('Bloc déplacé en position ' + (target + 1));
    if (!button.disabled) button.focus(); else (delta < 0 ? block.down : block.up).focus();
  }

  [['text', '+ Texte'], ['cards', '+ Grille de cartes'], ['embed', '+ Vidéo (YouTube, Vimeo)']].forEach((pair) => {
    addBar.appendChild(el('button', {
      type: 'button',
      class: 'btn btn-secondary btn-small',
      text: pair[1],
      onclick: () => { addBlock({ type: pair[0] }, true); markDirty(); },
    }));
  });

  initial.forEach((data) => addBlock(data, false));
  if (initial.length === 0) addBlock({ type: 'text' }, false);

  const form = root.closest('form');
  const output = document.getElementById('blocks-json');
  form.addEventListener('submit', () => {
    output.value = JSON.stringify(blocks.map((block) => {
      if (block.type === 'text') return { type: 'text', html: block.content.getHtml() };
      return block.content.getData();
    }));
  });
}

// ---------------------------------------------------------------------------
// Page-level behaviour
// ---------------------------------------------------------------------------

function initCounters() {
  document.querySelectorAll('[data-counter]').forEach((field) => {
    const ideal = parseInt(field.getAttribute('data-counter'), 10);
    const counter = el('span', { class: 'counter', 'aria-live': 'polite' });
    field.insertAdjacentElement('afterend', counter);
    const update = () => {
      const n = field.value.length;
      counter.textContent = n + ' / ' + ideal + ' caractères conseillés';
      counter.classList.toggle('warn-text', n > ideal);
    };
    field.addEventListener('input', update);
    update();
  });
}

function initUnsavedWarning() {
  const forms = document.querySelectorAll('form[data-unsaved-warning]');
  if (!forms.length) return;
  forms.forEach((form) => {
    form.addEventListener('input', markDirty);
    form.addEventListener('change', markDirty);
    form.addEventListener('submit', () => { dirty = false; });
  });
  window.addEventListener('beforeunload', (event) => {
    if (dirty) { event.preventDefault(); event.returnValue = ''; }
  });
}

function initConfirmForms() {
  document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (!window.confirm(form.getAttribute('data-confirm'))) event.preventDefault();
    });
  });
}

document.addEventListener('DOMContentLoaded', () => {
  const editor = document.getElementById('block-editor');
  if (editor) initBlockEditor(editor);
  initMediaFields(document);
  initCounters();
  initUnsavedWarning();
  initConfirmForms();
  const summary = document.getElementById('error-summary');
  if (summary) summary.focus();
  dirty = false;
});
