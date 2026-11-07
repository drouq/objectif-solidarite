/* Public site: load a YouTube or Vimeo player only when the visitor asks for it. */
(function () {
  'use strict';
  var videos = document.querySelectorAll('.video[data-src]');
  Array.prototype.forEach.call(videos, function (box) {
    var button = box.querySelector('.video-play');
    if (!button) { return; }
    button.addEventListener('click', function () {
      var iframe = document.createElement('iframe');
      iframe.src = box.getAttribute('data-src') + '&autoplay=1';
      iframe.title = 'Vidéo ' + (box.getAttribute('data-provider') || '');
      iframe.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
      iframe.setAttribute('allowfullscreen', '');
      iframe.referrerPolicy = 'strict-origin-when-cross-origin';
      box.innerHTML = '';
      box.appendChild(iframe);
      iframe.focus();
    });
  });
})();
