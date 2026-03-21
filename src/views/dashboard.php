<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fileshare</title>
    <link rel="stylesheet" href="/simple.min.css">
    <style>
        body { grid-template-columns: 1fr min(65rem,90%) 1fr; }

        /* Upload form */
        details summary { cursor: pointer; font-weight: bold; }
        details[open] summary { margin-bottom: .75rem; }
        .upload-form { display: flex; flex-direction: column; gap: .75rem; margin-top: .5rem; }
        .upload-options { display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; }

        /* Badges */
        .badge {
            font-size: .72rem;
            padding: .1rem .4rem;
            border-radius: 3px;
            background: var(--accent-bg);
            color: var(--text-light);
            white-space: nowrap;
        }
        .badge-private { background: #fce8e8; color: #b00; }
        .badges { display: flex; flex-wrap: wrap; gap: .3rem; margin-top: .25rem; }

        /* File table */
        .folder-heading { margin: 1.5rem 0 .25rem; font-size: 1rem; color: var(--text-light); }
        table { width: 100%; table-layout: fixed; }
        table td { vertical-align: top; padding: .5rem .4rem; }
        td.td-file { word-break: break-all; }
        td.td-actions { width: 1%; white-space: nowrap; text-align: right; }

        /* Actions */
        .file-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: .35rem; align-items: center; }
        .file-actions form { margin: 0; }
        .file-actions button { font-size: .82rem; padding: .2rem .55rem; margin: 0; }

        /* Expiry group: visually joined select + button */
        .expiry-group { display: flex; align-items: stretch; }
        .expiry-group select {
            font-size: .82rem;
            padding: .2rem .35rem;
            border-right: none;
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
        }
        .expiry-group button {
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
        }

        @media (max-width: 480px) {
            td.td-actions { width: auto; white-space: normal; }
            .file-actions { justify-content: flex-start; }
        }
    </style>
</head>
<body>
    <header>
        <h1>Fileshare</h1>
        <nav><a href="/logout">Log out</a></nav>
    </header>
    <main>
        <?php if ($flash): ?><p><strong><?= h($flash) ?></strong></p><?php endif; ?>

        <section>
            <details>
                <summary>Upload a file</summary>
                <form class="upload-form" method="post" action="/upload" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <label>File
                        <input type="file" name="file" required>
                    </label>
                    <label>Folder <small>(optional)</small>
                        <input type="text" name="folder" placeholder="e.g. projects/2024">
                    </label>
                    <div class="upload-options">
                        <label><input type="checkbox" name="private"> Private</label>
                        <label>Expires
                            <select name="expiry">
                                <option value="never">Never</option>
                                <option value="1h">1 hour</option>
                                <option value="6h">6 hours</option>
                                <option value="24h">24 hours</option>
                                <option value="3d">3 days</option>
                                <option value="7d">7 days</option>
                                <option value="30d">30 days</option>
                            </select>
                        </label>
                    </div>
                    <div><button type="submit">Upload</button></div>
                </form>
            </details>
        </section>

        <section>
            <h2>Files</h2>
            <?php if (empty($folders)): ?>
                <p>No files yet.</p>
            <?php else: ?>
                <?php foreach ($folders as $folder => $entries): ?>
                <h3 class="folder-heading"><?= $folder !== '' ? h($folder) . '/' : 'Root' ?></h3>
                <table>
                    <tbody>
                        <?php foreach ($entries as $entry): ?>
                        <?php $filename = basename($entry['path']); ?>
                        <tr>
                            <td class="td-file">
                                <a href="/download/<?= h($entry['path']) ?>"><?= h($filename) ?></a>
                                <div class="badges">
                                    <?php if ($entry['private']): ?>
                                        <span class="badge badge-private">private</span>
                                    <?php else: ?>
                                        <span class="badge">public</span>
                                    <?php endif; ?>
                                    <span class="badge">expires: <?= h(formatExpiry($entry['expires'])) ?></span>
                                </div>
                            </td>
                            <td class="td-actions">
                                <div class="file-actions">
                                    <form method="post" action="/toggle/<?= h($entry['path']) ?>">
                                        <?= csrfField() ?>
                                        <button type="submit"><?= $entry['private'] ? 'Make public' : 'Make private' ?></button>
                                    </form>
                                    <form method="post" action="/expiry/<?= h($entry['path']) ?>">
                                        <?= csrfField() ?>
                                        <div class="expiry-group">
                                            <select name="expiry">
                                                <option value="never">Never</option>
                                                <option value="1h">1h</option>
                                                <option value="6h">6h</option>
                                                <option value="24h">24h</option>
                                                <option value="3d">3d</option>
                                                <option value="7d">7d</option>
                                                <option value="30d">30d</option>
                                            </select>
                                            <button type="submit">Set expiry</button>
                                        </div>
                                    </form>
                                    <form method="post" action="/delete/<?= h($entry['path']) ?>"
                                          onsubmit="return confirm('Delete <?= h(addslashes($filename)) ?>?')">
                                        <?= csrfField() ?>
                                        <button type="submit">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
