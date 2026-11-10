<?php
require_once __DIR__ . '/../includes/database.php';

$error = '';
$columns = [];
$record = null;
$primaryColumns = [];
$editableColumns = [];
$imageColumn = null;
$newUploadPath = null;

// Ambil data terpilih berdasarkan primary key dan proses perubahan dari form.
try {
    $connection = dbConnection();
    $columns = getTableColumns($connection);
    if (!$columns) {
        throw new RuntimeException('Tabel tidak memiliki kolom yang dapat diedit.');
    }
    $primaryColumns = getPrimaryColumns($columns);
    $editableColumns = getEditableColumns($columns, $primaryColumns, true);
    $imageColumn = getImageColumn($columns);

    $keySource = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST'
        ? (isset($_POST['key']) && is_array($_POST['key']) ? $_POST['key'] : [])
        : (isset($_GET['key']) && is_array($_GET['key']) ? $_GET['key'] : []);
    $keyValues = getPrimaryKeyValues($primaryColumns, $keySource);
    $conditions = getPrimaryKeyConditions($primaryColumns);
    $selectStatement = executeStatement(
        $connection,
        'SELECT * FROM ' . quoteIdentifier(DB_TABLE) . ' WHERE ' . implode(' AND ', $conditions) . ' LIMIT 1',
        $keyValues
    );
    $record = $selectStatement->get_result()->fetch_assoc();

    if (!$record) {
        throw new RuntimeException('Data yang akan diedit tidak ditemukan.');
    }

    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $assignments = [];
        $values = [];
        foreach ($editableColumns as $column) {
            $name = $column['Field'];
            if ($imageColumn && $name === $imageColumn['Field']) {
                continue;
            }
            if (!array_key_exists($name, $_POST)) {
                if (isOptionalCapacityColumn($column)) {
                    continue;
                }
                if ($column['Null'] === 'NO' && $column['Default'] === null) {
                    throw new RuntimeException('Kolom ' . $name . ' wajib diisi.');
                }
                continue;
            }
            $assignments[] = quoteIdentifier($name) . ' = ?';
            $values[] = $_POST[$name] === ''
                && ($column['Null'] === 'YES' || isOptionalCapacityColumn($column))
                ? null
                : $_POST[$name];
        }

        if ($imageColumn) {
            $imageInput = getImageInputValue($imageColumn, $record[$imageColumn['Field']], $newUploadPath);
            if ($imageInput['provided']) {
                $assignments[] = quoteIdentifier($imageColumn['Field']) . ' = ?';
                $values[] = $imageInput['value'];
            }
        }

        if (!$assignments) {
            throw new RuntimeException('Tidak ada kolom yang dapat diperbarui.');
        }

        try {
            executeStatement(
                $connection,
                'UPDATE ' . quoteIdentifier(DB_TABLE) . ' SET ' . implode(', ', $assignments) . ' WHERE ' . implode(' AND ', $conditions),
                array_merge($values, $keyValues)
            );
        } catch (Throwable $exception) {
            if ($newUploadPath !== null && is_file($newUploadPath)) {
                unlink($newUploadPath);
            }
            throw $exception;
        }
        redirectToList('Data berhasil diperbarui.');
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
    <title>Edit Data</title>
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
        <h1>Edit Data</h1>
        <?php if ($error !== ''): ?>
            <p class="error">Terjadi kesalahan: <?php echo escapeHtml($error); ?></p>
        <?php endif; ?>
        <?php if ($record): ?>
            <form method="post" enctype="multipart/form-data">
                <?php foreach ($primaryColumns as $primaryColumn): ?>
                    <input type="hidden" name="key[<?php echo escapeHtml($primaryColumn); ?>]" value="<?php echo escapeHtml($record[$primaryColumn]); ?>">
                <?php endforeach; ?>
                <div class="form-grid">
                    <?php foreach ($editableColumns as $column): ?>
                        <?php
                        $fieldName = $column['Field'];
                        $value = isset($_POST[$fieldName]) ? $_POST[$fieldName] : $record[$fieldName];
                        if ($imageColumn && $fieldName === $imageColumn['Field']) {
                            $imageValue = isset($_POST[$fieldName . '_url']) && $_POST[$fieldName . '_url'] !== ''
                                ? $_POST[$fieldName . '_url']
                                : $record[$fieldName];
                            renderImageInput($column, $imageValue, '../');
                        } else {
                            renderInput($column, $value);
                        }
                        ?>
                    <?php endforeach; ?>
                    <?php if ($imageColumn && !in_array($imageColumn['Field'], array_column($editableColumns, 'Field'), true)): ?>
                        <?php
                        $imageValue = isset($_POST[$imageColumn['Field'] . '_url']) && $_POST[$imageColumn['Field'] . '_url'] !== ''
                            ? $_POST[$imageColumn['Field'] . '_url']
                            : $record[$imageColumn['Field']];
                        renderImageInput($imageColumn, $imageValue, '../');
                        ?>
                    <?php endif; ?>
                </div>
                <button class="button primary" type="submit">Simpan Perubahan</button>
                <a class="button secondary" href="../index.php">Batal</a>
            </form>
        <?php else: ?>
            <a class="button secondary" href="../index.php">Kembali ke daftar data</a>
        <?php endif; ?>
    </section>
</main>
<?php renderImagePreviewScript(); ?>
</body>
</html>
