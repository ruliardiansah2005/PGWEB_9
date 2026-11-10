<?php
require_once __DIR__ . '/includes/database.php';

$columns = [];
$primaryColumns = [];
$imageColumn = null;
$records = [];
$error = '';
$message = isset($_GET['message']) ? (string) $_GET['message'] : '';

// Muat struktur tabel dan semua data untuk ditampilkan pada daftar.
try {
    $connection = dbConnection();
    $columns = getTableColumns($connection);
    if (!$columns) {
        throw new RuntimeException('Tabel tidak memiliki kolom yang dapat dibaca.');
    }
    $primaryColumns = getPrimaryColumns($columns);
    $imageColumn = getImageColumn($columns);
    $autoIncrementColumn = null;
    foreach ($columns as $column) {
        if (stripos($column['Extra'], 'auto_increment') !== false) {
            $autoIncrementColumn = $column['Field'];
            break;
        }
    }
    $orderBy = [];
    foreach ($primaryColumns as $primaryColumn) {
        $orderBy[] = quoteIdentifier($primaryColumn) . ' ASC';
    }
    $records = $connection->query(
        'SELECT * FROM ' . quoteIdentifier(DB_TABLE) . ' ORDER BY ' . implode(', ', $orderBy)
    )->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Data Sebaran Fasilitas D.I. Yogyakarta</title>
    <style>
        :root { color-scheme: light; font-family: Arial, sans-serif; color: #1f2937; background: #f3f6fa; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 32px 16px; }
        main { max-width: 1100px; margin: 0 auto; }
        h1 { margin: 0 0 8px; }
        .subtitle { margin: 0 0 24px; color: #64748b; }
        .panel { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 14px #0f172a0a; }
        .toolbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 16px; }
        .toolbar h2 { margin: 0; font-size: 20px; }
        .button { display: inline-block; border: 0; border-radius: 7px; padding: 9px 13px; font-size: 14px; font-weight: 600; text-decoration: none; cursor: pointer; }
        .primary { background: #2563eb; color: #fff; }
        .secondary { background: #e2e8f0; color: #1e293b; }
        .danger { background: #fee2e2; color: #b91c1c; }
        .message, .error { padding: 12px 14px; border-radius: 8px; margin-bottom: 16px; }
        .message { background: #dcfce7; color: #166534; }
        .error { background: #fee2e2; color: #991b1b; overflow-wrap: anywhere; }
        .table-wrap { overflow-x: auto; }
        table { border-collapse: collapse; width: 100%; }
        th, td { padding: 11px 12px; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: top; }
        th { background: #f8fafc; font-size: 13px; white-space: nowrap; }
        td { font-size: 14px; }
        .actions { white-space: nowrap; }
        .actions form { display: inline; }
        .actions .button { padding: 7px 10px; }
        .image-thumb { display: block; width: 120px; height: 80px; object-fit: cover; border: 1px solid #e2e8f0; border-radius: 6px; }
        .image-error { display: none; color: #b91c1c; }
        .empty { color: #64748b; text-align: center; padding: 24px; }
        @media (max-width: 600px) { body { padding: 20px 10px; } .panel { padding: 14px; } .toolbar { align-items: flex-start; } }
    </style>
</head>
<body>
<main>
    <h1>Data Sebaran Fasilitas D.I. Yogyakarta</h1>

    <!-- Tampilkan notifikasi dan kesalahan yang dikirim dari proses sebelumnya. -->
    <?php if ($message !== ''): ?>
        <div class="message"><?php echo escapeHtml($message); ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="error">Terjadi kesalahan: <?php echo escapeHtml($error); ?></div>
    <?php endif; ?>

    <section class="panel">
        <!-- Tampilkan daftar data beserta aksi tambah, edit, dan hapus. -->
        <div class="toolbar">
            <h2>Daftar Data</h2>
            <?php if (!$error): ?>
                <a class="button primary" href="input/input.php">+ Tambah Data</a>
            <?php endif; ?>
        </div>
        <?php if (!$error): ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <?php foreach ($columns as $column): ?>
                            <th><?php echo $autoIncrementColumn === $column['Field']
                                ? 'No'
                                : escapeHtml(getColumnLabel($column['Field'])); ?></th>
                        <?php endforeach; ?>
                        <th>Aksi</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$records): ?>
                        <tr><td class="empty" colspan="<?php echo count($columns) + 1; ?>">Belum ada data.</td></tr>
                    <?php else: ?>
                        <?php $rowNumber = 1; ?>
                        <?php foreach ($records as $record): ?>
                            <?php
                            $keyParameters = [];
                            foreach ($primaryColumns as $primaryColumn) {
                                $keyParameters['key[' . $primaryColumn . ']'] = $record[$primaryColumn];
                            }
                            $editUrl = 'edit/edit.php?' . http_build_query($keyParameters);
                            ?>
                            <tr>
                                <?php foreach ($columns as $column): ?>
                                    <?php if ($autoIncrementColumn === $column['Field']): ?>
                                        <td><?php echo $rowNumber; ?></td>
                                    <?php elseif ($imageColumn && $column['Field'] === $imageColumn['Field']): ?>
                                        <?php $imageUrl = getImageDisplayUrl($record[$column['Field']]); ?>
                                        <td>
                                            <?php if ($imageUrl !== ''): ?>
                                                <img class="image-thumb" src="<?php echo escapeHtml($imageUrl); ?>" alt="Gambar <?php echo escapeHtml($record['Nama'] ?? 'data'); ?>" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='inline';">
                                                <span class="image-error">Gambar tidak dapat dimuat. Pastikan tautan langsung ke file gambar.</span>
                                            <?php elseif (!empty($record[$column['Field']])): ?>
                                                Tautan gambar tidak valid
                                            <?php else: ?>
                                                Belum ada gambar
                                            <?php endif; ?>
                                        </td>
                                    <?php else: ?>
                                        <td><?php echo escapeHtml($record[$column['Field']]); ?></td>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <td class="actions">
                                    <a class="button secondary" href="<?php echo escapeHtml($editUrl); ?>">Edit</a>
                                    <form action="delete.php" method="post" onsubmit="return confirm('Yakin ingin menghapus data ini?');">
                                        <input type="hidden" name="action" value="delete">
                                        <?php foreach ($primaryColumns as $primaryColumn): ?>
                                            <input type="hidden" name="key[<?php echo escapeHtml($primaryColumn); ?>]" value="<?php echo escapeHtml($record[$primaryColumn]); ?>">
                                        <?php endforeach; ?>
                                        <button class="button danger" type="submit">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                            <?php $rowNumber++; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
