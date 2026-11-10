<?php
require_once __DIR__ . '/includes/database.php';

// Pastikan penghapusan hanya dapat dilakukan melalui permintaan POST.
if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Metode tidak diizinkan.');
}

// Validasi aksi, hapus baris berdasarkan primary key, lalu tampilkan hasilnya.
try {
    if (!isset($_POST['action']) || $_POST['action'] !== 'delete') {
        throw new RuntimeException('Aksi penghapusan tidak valid.');
    }

    $connection = dbConnection();
    $primaryColumns = getPrimaryColumns(getTableColumns($connection));
    $keyValues = getPrimaryKeyValues(
        $primaryColumns,
        isset($_POST['key']) && is_array($_POST['key']) ? $_POST['key'] : []
    );
    $statement = executeStatement(
        $connection,
        'DELETE FROM ' . quoteIdentifier(DB_TABLE) . ' WHERE ' . implode(' AND ', getPrimaryKeyConditions($primaryColumns)),
        $keyValues
    );

    if ($statement->affected_rows !== 1) {
        throw new RuntimeException('Data tidak ditemukan atau tidak berhasil dihapus.');
    }

    redirectToList('Data berhasil dihapus.', './');
} catch (Throwable $exception) {
    http_response_code(400);
    $error = $exception->getMessage();
}
?>
<!DOCTYPE html>
<html lang="id">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Gagal Menghapus Data</title></head>
<body>
    <h1>Gagal menghapus data</h1>
    <p><?php echo escapeHtml($error); ?></p>
    <a href="index.php">Kembali ke daftar data</a>
</body>
</html>
