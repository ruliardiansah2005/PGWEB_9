<?php
require_once __DIR__ . '/../includes/database.php';

$error = '';
$columns = [];
$imageColumn = null;
$newUploadPath = null;

// Siapkan form berdasarkan struktur tabel dan proses data saat form dikirim.
try {
    $connection = dbConnection();
    $columns = getTableColumns($connection);
    if (!$columns) {
        throw new RuntimeException('Tabel tidak memiliki kolom yang dapat diisi.');
    }
    $primaryColumns = getPrimaryColumns($columns);
    $editableColumns = getEditableColumns($columns, $primaryColumns);
    $imageColumn = getImageColumn($columns);

    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $fields = [];
        $values = [];
        foreach ($editableColumns as $column) {
            $name = $column['Field'];
            if ($imageColumn && $name === $imageColumn['Field']) {
                continue;
            }
            if (!array_key_exists($name, $_POST)) {
                if (isOptionalCapacityColumn($column)) {
                    $fields[] = quoteIdentifier($name);
                    $values[] = null;
                    continue;
                }
                if ($column['Null'] === 'NO' && $column['Default'] === null) {
                    throw new RuntimeException('Kolom ' . $name . ' wajib diisi.');
                }
                continue;
            }
            $fields[] = quoteIdentifier($name);
            $values[] = $_POST[$name] === ''
                && ($column['Null'] === 'YES' || isOptionalCapacityColumn($column))
                ? null
                : $_POST[$name];
        }

        if ($imageColumn) {
            $imageInput = getImageInputValue($imageColumn, null, $newUploadPath);
            if ($imageInput['provided']) {
                $fields[] = quoteIdentifier($imageColumn['Field']);
                $values[] = $imageInput['value'];
            } elseif ($imageColumn['Null'] === 'NO' && $imageColumn['Default'] === null) {
                throw new RuntimeException('Gambar wajib diisi dengan tautan atau file unggahan.');
            }
        }

        if (!$fields) {
            throw new RuntimeException('Tidak ada data yang dapat disimpan.');
        }

        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        try {
            executeStatement(
                $connection,
                'INSERT INTO ' . quoteIdentifier(DB_TABLE) . ' (' . implode(', ', $fields) . ') VALUES (' . $placeholders . ')',
                $values
            );
        } catch (Throwable $exception) {
            if ($newUploadPath !== null && is_file($newUploadPath)) {
                unlink($newUploadPath);
            }
            throw $exception;
        }
        redirectToList('Data berhasil ditambahkan.');
    }
} catch (Throwable $exception) {
    if ($newUploadPath !== null && is_file($newUploadPath)) {
        unlink($newUploadPath);
    }
    $error = $exception->getMessage();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tambah Data</title>
    <style>
        body { margin: 0; padding: 32px 16px; background: #f3f6fa; color: #1f2937; font-family: Arial, sans-serif; }
        main { max-width: 800px; margin: auto; }
        .panel { padding: 20px; background: white; border: 1px solid #e2e8f0; border-radius: 12px; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; margin: 16px 0; }
        .field { display: flex; flex-direction: column; gap: 6px; font-size: 14px; font-weight: 600; }
        input, select, textarea { width: 100%; box-sizing: border-box; border: 1px solid #cbd5e1; border-radius: 6px; padding: 9px 10px; font: inherit; }
        textarea { min-height: 90px; }
        .button { display: inline-block; border: 0; border-radius: 7px; padding: 9px 13px; font-size: 14px; font-weight: 600; text-decoration: none; cursor: pointer; }
        .primary { background: #2563eb; color: white; }
        .secondary { background: #e2e8f0; color: #1e293b; }
        .error { padding: 12px 14px; border-radius: 8px; background: #fee2e2; color: #991b1b; overflow-wrap: anywhere; }
        .image-field { display: flex; flex-direction: column; gap: 8px; font-size: 14px; font-weight: 600; }
        .image-field small { color: #64748b; font-weight: 400; }
        .image-preview { object-fit: contain; border: 1px solid #e2e8f0; border-radius: 6px; }
        .image-status { color: #b91c1c !important; }
    </style>
</head>
<body>
<main>
    <section class="panel">
        <h1>Tambah Data</h1>
        <?php if ($error !== ''): ?>
            <p class="error">Terjadi kesalahan: <?php echo escapeHtml($error); ?></p>
        <?php endif; ?>
        <?php if ($columns): ?>
            <form method="post" enctype="multipart/form-data">
                <div class="form-grid">
                    <?php foreach ($editableColumns as $column): ?>
                        <?php if ($imageColumn && $column['Field'] === $imageColumn['Field']): ?>
                            <?php renderImageInput($column, isset($_POST[$column['Field'] . '_url']) ? $_POST[$column['Field'] . '_url'] : ''); ?>
                        <?php else: ?>
                            <?php renderInput($column, isset($_POST[$column['Field']]) ? $_POST[$column['Field']] : ''); ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($imageColumn && !in_array($imageColumn['Field'], array_column($editableColumns, 'Field'), true)): ?>
                        <?php renderImageInput($imageColumn, isset($_POST[$imageColumn['Field'] . '_url']) ? $_POST[$imageColumn['Field'] . '_url'] : ''); ?>
                    <?php endif; ?>
                </div>
                <button class="button primary" type="submit">Simpan Data</button>
                <a class="button secondary" href="../index.php">Batal</a>
            </form>
        <?php endif; ?>
    </section>
</main>
<?php renderImagePreviewScript(); ?>
</body>
</html>
