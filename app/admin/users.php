<?php
/** Allowlist management (admin only). @var array $users @var int $currentId */
?>
<h1>Utilisateurs</h1>
<p>Seules les personnes de cette liste peuvent se connecter, avec leur compte Google (Gmail). Il n'y a aucun mot de passe à gérer.</p>

<section class="panel" aria-labelledby="add-user-title">
  <h2 id="add-user-title">Ajouter une personne</h2>
  <form method="post" action="/admin/utilisateurs" class="form-inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <div class="field">
      <label for="user-email">Adresse Gmail</label>
      <input id="user-email" name="email" type="email" required autocomplete="off">
    </div>
    <div class="field">
      <label for="user-role">Rôle</label>
      <select id="user-role" name="role">
        <option value="editor">Éditeur : pages et médias</option>
        <option value="admin">Administrateur : tout, y compris réglages et utilisateurs</option>
      </select>
    </div>
    <button type="submit" class="btn btn-primary">Ajouter</button>
  </form>
</section>

<div class="table-wrap">
<table>
  <caption class="visually-hidden">Personnes autorisées</caption>
  <thead><tr><th scope="col">Adresse</th><th scope="col">Rôle</th><th scope="col">Dernière connexion</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <?php $locked = Users::isConfigAdmin((string) $u['email']) || (int) $u['id'] === $currentId; ?>
    <tr>
      <td><?= e($u['email']) ?><?= $u['name'] !== '' && $u['name'] !== 'Test local' ? '<br><span class="help">' . e($u['name']) . '</span>' : '' ?><?= (int) $u['id'] === $currentId ? ' <span class="badge badge-info">vous</span>' : '' ?></td>
      <td>
        <?php if ($locked): ?>
          <?= e(Users::ROLES[$u['role']] ?? $u['role']) ?>
        <?php else: ?>
        <form method="post" action="/admin/utilisateurs" class="inline-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="role">
          <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
          <label class="visually-hidden" for="role-<?= (int) $u['id'] ?>">Rôle de <?= e($u['email']) ?></label>
          <select id="role-<?= (int) $u['id'] ?>" name="role">
            <?php foreach (Users::ROLES as $value => $label): ?>
              <option value="<?= e($value) ?>" <?= $u['role'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-secondary btn-small">Changer</button>
        </form>
        <?php endif; ?>
      </td>
      <td><?= $u['last_login_at'] ? e(format_date_fr((string) $u['last_login_at'])) : 'jamais' ?></td>
      <td>
        <?php if (!$locked): ?>
        <form method="post" action="/admin/utilisateurs" class="inline-form" data-confirm="Retirer l'accès de <?= e($u['email']) ?> ?">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="remove">
          <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
          <button type="submit" class="button-link danger-link">Retirer l'accès<span class="visually-hidden"> de <?= e($u['email']) ?></span></button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="help">L'administrateur principal est défini dans le fichier config.php. Il garde toujours l'accès.</p>
