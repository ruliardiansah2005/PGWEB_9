<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

const DB_SERVER = 'localhost';
const DB_USERNAME = 'root';
const DB_PASSWORD = '';
const DB_NAME = 'pgweb9';
const DB_TABLE = 'bgn_wsb';

/** Membuat koneksi MySQL dan mengatur charset agar mendukung UTF-8. */
function dbConnection()
{
    $connection = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
    $connection->set_charset('utf8mb4');
    return $connection;
}

/** Mengamankan nilai sebelum ditampilkan sebagai teks HTML. */
function escapeHtml($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Mengubah nama kolom menjadi label yang mudah dibaca pengguna. */
function getColumnLabel($name)
{
    $normalizedName = strtolower(preg_replace('/[^a-z0-9]/i', '', $name));
    if ($normalizedName === 'kapasitas') {
        return 'Jenis Fasilitas';
    }

    return ucwords(str_replace('_', ' ', $name));
}

/** Membungkus nama tabel atau kolom agar aman digunakan sebagai identifier SQL. */
function quoteIdentifier($identifier)
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/** Menjalankan query terparameterisasi untuk mencegah nilai data ditafsirkan sebagai SQL. */
function executeStatement($connection, $sql, $values = [])
{
    $statement = $connection->prepare($sql);

    if ($values) {
        $types = str_repeat('s', count($values));
        $parameters = [$types];
        foreach ($values as $index => $value) {
            $parameters[] = &$values[$index];
        }
        call_user_func_array([$statement, 'bind_param'], $parameters);
    }

    $statement->execute();
    return $statement;
}

/** Mengambil definisi semua kolom pada tabel aplikasi. */
function getTableColumns($connection)
{
    $columns = [];
    $result = $connection->query('SHOW COLUMNS FROM ' . quoteIdentifier(DB_TABLE));
    while ($column = $result->fetch_assoc()) {
        $columns[] = $column;
    }
    return $columns;
}

/** Mengambil nama kolom primary key dan memastikan tabel memilikinya. */
function getPrimaryColumns($columns)
{
    $primaryColumns = [];
    foreach ($columns as $column) {
        if ($column['Key'] === 'PRI') {
            $primaryColumns[] = $column['Field'];
        }
    }

    if (!$primaryColumns) {
        throw new RuntimeException('Tabel harus memiliki primary key agar data dapat diedit dan dihapus dengan aman.');
    }

    return $primaryColumns;
}

/** Membaca dan memvalidasi nilai primary key dari data permintaan. */
function getPrimaryKeyValues($primaryColumns, $source)
{
    $values = [];
    foreach ($primaryColumns as $column) {
        if (!isset($source[$column]) || is_array($source[$column])) {
            throw new RuntimeException('Nilai kunci data tidak lengkap.');
        }
        $values[] = (string) $source[$column];
    }
    return $values;
}

/** Membuat kondisi SQL berparameter untuk seluruh kolom primary key. */
function getPrimaryKeyConditions($primaryColumns)
{
    $conditions = [];
    foreach ($primaryColumns as $column) {
        $conditions[] = quoteIdentifier($column) . ' = ?';
    }
    return $conditions;
}

/** Memilih kolom yang dapat diisi atau diubah, tanpa kolom otomatis. */
function getEditableColumns($columns, $primaryColumns, $skipPrimary = false)
{
    $editableColumns = [];
    foreach ($columns as $column) {
        $isGenerated = stripos($column['Extra'], 'auto_increment') !== false
            || stripos($column['Extra'], 'generated') !== false;
        $isPrimary = in_array($column['Field'], $primaryColumns, true);
        if (!$isGenerated && !($skipPrimary && $isPrimary)) {
            $editableColumns[] = $column;
        }
    }
    return $editableColumns;
}

/** Memeriksa apakah kolom kapasitas bersifat opsional pada formulir. */
function isOptionalCapacityColumn($column)
{
    $normalizedName = strtolower(preg_replace('/[^a-z0-9]/i', '', $column['Field']));
    return $normalizedName === 'kapasitas';
}

/** Mencari kolom yang menyimpan nama file atau tautan gambar. */
function getImageColumn($columns)
{
    foreach ($columns as $column) {
        $normalizedName = strtolower(preg_replace('/[^a-z0-9]/i', '', $column['Field']));
        if (in_array($normalizedName, ['gambar', 'foto', 'gambarfoto', 'fotogambar'], true)) {
            return $column;
        }
    }
    return null;
}

/** Memvalidasi dan membentuk URL tampilan untuk gambar lokal atau gambar web. */
function getImageDisplayUrl($value, $pathPrefix = '')
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    if (filter_var($value, FILTER_VALIDATE_URL)) {
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) ? $value : '';
    }

    if (strpos($value, '..') !== false || strpos($value, '\\') !== false
        || !preg_match('/^[a-zA-Z0-9_\/.%\-]+$/', $value)) {
        return '';
    }

    return $pathPrefix . $value;
}

/** Memvalidasi unggahan atau tautan gambar, lalu mengembalikan nilai untuk disimpan. */
function getImageInputValue($column, $currentValue = null, &$newUploadPath = null)
{
    $name = $column['Field'];
    $urlField = $name . '_url';
    $uploadField = $name . '_upload';
    $url = isset($_POST[$urlField]) && is_string($_POST[$urlField]) ? trim($_POST[$urlField]) : '';
    $upload = isset($_FILES[$uploadField]) && is_array($_FILES[$uploadField])
        ? $_FILES[$uploadField]
        : null;
    $hasUpload = $upload && isset($upload['error']) && $upload['error'] !== UPLOAD_ERR_NO_FILE;

    if ($url !== '' && $hasUpload) {
        throw new RuntimeException('Pilih unggah gambar atau gunakan tautan, jangan keduanya.');
    }

    if ($hasUpload) {
        if ($upload['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Unggah gambar gagal (kode ' . (int) $upload['error'] . ').');
        }
        if (!isset($upload['tmp_name'], $upload['size']) || !is_uploaded_file($upload['tmp_name'])) {
            throw new RuntimeException('File unggahan tidak valid.');
        }
        if ((int) $upload['size'] > 5 * 1024 * 1024) {
            throw new RuntimeException('Ukuran gambar maksimal 5 MB.');
        }

        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $fileInfo->file($upload['tmp_name']);
        $allowedTypes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
        if (!isset($allowedTypes[$mimeType])) {
            throw new RuntimeException('Format gambar harus JPG, PNG, GIF, atau WEBP.');
        }

        $uploadDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
        if (!is_dir($uploadDirectory)
            && !mkdir($uploadDirectory, 0755, true)
            && !is_dir($uploadDirectory)) {
            throw new RuntimeException('Folder uploads tidak dapat dibuat.');
        }
        if (!is_writable($uploadDirectory)) {
            throw new RuntimeException('Folder uploads tidak dapat ditulisi.');
        }

        $fileName = bin2hex(random_bytes(16)) . '.' . $allowedTypes[$mimeType];
        $destination = $uploadDirectory . DIRECTORY_SEPARATOR . $fileName;
        if (!move_uploaded_file($upload['tmp_name'], $destination)) {
            throw new RuntimeException('Gambar gagal disimpan ke folder uploads.');
        }

        $newUploadPath = $destination;
        return ['provided' => true, 'value' => 'uploads/' . $fileName];
    }

    if ($url !== '') {
        if (strlen($url) > 2048) {
            throw new RuntimeException('Tautan gambar maksimal 2048 karakter.');
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)
            || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new RuntimeException('Masukkan tautan gambar yang valid dan diawali http:// atau https://.');
        }
        return ['provided' => true, 'value' => $url];
    }

    return ['provided' => false, 'value' => $currentValue];
}

/** Menampilkan input tautan/unggahan gambar beserta pratinjaunya. */
function renderImageInput($column, $value = '', $pathPrefix = '')
{
    $name = $column['Field'];
    $urlField = $name . '_url';
    $uploadField = $name . '_upload';
    $previewId = 'preview-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $name);
    $previewUrl = getImageDisplayUrl($value, $pathPrefix);
    $urlValue = filter_var((string) $value, FILTER_VALIDATE_URL)
        && in_array(strtolower((string) parse_url((string) $value, PHP_URL_SCHEME)), ['http', 'https'], true)
        ? $value
        : '';

    echo '<div class="field image-field">';
    echo '<span>' . escapeHtml(getColumnLabel(str_replace('/', ' ', $name))) . '</span>';
    echo '<label for="' . escapeHtml($previewId) . '-url">Tautan langsung ke file gambar (opsional)</label>';
    echo '<input class="image-url-input" id="' . escapeHtml($previewId) . '-url" type="url" name="' . escapeHtml($urlField) . '" maxlength="2048" value="' . escapeHtml($urlValue) . '" placeholder="https://contoh.com/gambar.jpg" data-preview="' . escapeHtml($previewId) . '">';
    echo '<label for="' . escapeHtml($previewId) . '-file">Atau unggah gambar (JPG, PNG, GIF, WEBP; maks. 5 MB)</label>';
    echo '<input class="image-file-input" id="' . escapeHtml($previewId) . '-file" type="file" name="' . escapeHtml($uploadField) . '" accept="image/jpeg,image/png,image/gif,image/webp" data-preview="' . escapeHtml($previewId) . '">';
    echo '<small>Gunakan tautan publik langsung ke gambar, bukan tautan halaman berbagi (misalnya halaman Google Drive).</small>';
    echo '<img id="' . escapeHtml($previewId) . '" class="image-preview" src="' . escapeHtml($previewUrl) . '" alt="Pratinjau gambar" style="max-width:180px;max-height:120px;' . ($previewUrl === '' ? 'display:none;' : '') . '">';
    echo '<small id="' . escapeHtml($previewId) . '-status" class="image-status" aria-live="polite"></small>';
    echo '</div>';
}

/** Menampilkan input formulir yang sesuai dengan tipe data kolom. */
function renderInput($column, $value = '', $required = true)
{
    $name = $column['Field'];
    $type = strtolower($column['Type']);
    $label = getColumnLabel($name);
    $requiredAttribute = $required
        && !isOptionalCapacityColumn($column)
        && $column['Null'] === 'NO'
        && $column['Default'] === null
        ? ' required'
        : '';
    $id = 'field-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $name);

    echo '<label class="field" for="' . escapeHtml($id) . '">';
    echo '<span>' . escapeHtml($label) . '</span>';

    if (preg_match('/^enum\\((.*)\\)$/i', $type, $matches)) {
        preg_match_all("/'((?:[^'\\\\\\\\]|\\\\\\\\.)*)'/", $matches[1], $optionMatches);
        echo '<select id="' . escapeHtml($id) . '" name="' . escapeHtml($name) . '"' . $requiredAttribute . '>';
        if ($column['Null'] !== 'NO') {
            echo '<option value="">-- Pilih --</option>';
        }
        foreach ($optionMatches[1] as $option) {
            $option = stripcslashes($option);
            echo '<option value="' . escapeHtml($option) . '"' . ((string) $value === $option ? ' selected' : '') . '>';
            echo escapeHtml($option) . '</option>';
        }
        echo '</select>';
    } elseif (!isOptionalCapacityColumn($column)
        && preg_match('/^(tinyint|smallint|mediumint|int|bigint|decimal|numeric|float|double|real)/', $type)) {
        $step = preg_match('/^(tinyint|smallint|mediumint|int|bigint)/', $type) ? '1' : 'any';
        echo '<input id="' . escapeHtml($id) . '" type="number" step="' . $step . '" name="' . escapeHtml($name) . '" value="' . escapeHtml($value) . '"' . $requiredAttribute . '>';
    } elseif (preg_match('/^(date|datetime|timestamp|time)/', $type)) {
        $inputType = $type === 'datetime' || $type === 'timestamp' ? 'datetime-local' : ($type === 'time' ? 'time' : 'date');
        $inputValue = (string) $value;
        if ($inputType === 'datetime-local') {
            $inputValue = str_replace(' ', 'T', substr($inputValue, 0, 16));
        }
        echo '<input id="' . escapeHtml($id) . '" type="' . $inputType . '" name="' . escapeHtml($name) . '" value="' . escapeHtml($inputValue) . '"' . $requiredAttribute . '>';
    } elseif (preg_match('/^(text|mediumtext|longtext)/', $type)) {
        echo '<textarea id="' . escapeHtml($id) . '" name="' . escapeHtml($name) . '"' . $requiredAttribute . '>' . escapeHtml($value) . '</textarea>';
    } else {
        echo '<input id="' . escapeHtml($id) . '" type="text" name="' . escapeHtml($name) . '" value="' . escapeHtml($value) . '"' . $requiredAttribute . '>';
    }

    echo '</label>';
}

/** Mengeluarkan JavaScript untuk memperbarui dan memvalidasi pratinjau gambar. */
function renderImagePreviewScript()
{
    echo '<script>
        /** Memproses setiap input tautan gambar. */
        document.querySelectorAll(".image-url-input").forEach(function (input) {
            /** Memperbarui pratinjau saat pengguna mengubah tautan gambar. */
            input.addEventListener("input", function () {
                var preview = document.getElementById(input.dataset.preview);
                var fileInput = document.querySelector(".image-file-input[data-preview=\\"" + input.dataset.preview + "\\"]");
                var status = document.getElementById(input.dataset.preview + "-status");
                if (input.value.trim() !== "") {
                    if (fileInput) fileInput.value = "";
                    if (status) status.textContent = "";
                    preview.src = input.value;
                    preview.style.display = "block";
                } else if (!fileInput || !fileInput.files.length) {
                    preview.removeAttribute("src");
                    preview.style.display = "none";
                    if (status) status.textContent = "";
                }
            });
        });
        /** Memproses setiap input unggahan gambar. */
        document.querySelectorAll(".image-file-input").forEach(function (input) {
            /** Memperbarui pratinjau lokal saat pengguna memilih file gambar. */
            input.addEventListener("change", function () {
                var preview = document.getElementById(input.dataset.preview);
                var urlInput = document.querySelector(".image-url-input[data-preview=\\"" + input.dataset.preview + "\\"]");
                var status = document.getElementById(input.dataset.preview + "-status");
                if (input.files.length) {
                    if (urlInput) urlInput.value = "";
                    if (status) status.textContent = "";
                    preview.src = URL.createObjectURL(input.files[0]);
                    preview.style.display = "block";
                }
            });
        });
        /** Memproses setiap elemen pratinjau gambar. */
        document.querySelectorAll(".image-preview").forEach(function (preview) {
            var status = document.getElementById(preview.id + "-status");
            /** Menghapus pesan kesalahan setelah gambar berhasil dimuat. */
            preview.addEventListener("load", function () {
                if (status) status.textContent = "";
            });
            /** Menampilkan pesan dan menyembunyikan pratinjau jika gambar gagal. */
            preview.addEventListener("error", function () {
                if (status && preview.getAttribute("src")) {
                    status.textContent = "Gambar tidak dapat dimuat. Pastikan tautan langsung, publik, dan mengarah ke file gambar.";
                    preview.style.display = "none";
                }
            });
            if (preview.complete && preview.naturalWidth === 0 && preview.getAttribute("src") && status) {
                status.textContent = "Gambar tidak dapat dimuat. Pastikan tautan langsung, publik, dan mengarah ke file gambar.";
                preview.style.display = "none";
            }
        });
    </script>';
}

/** Mengarahkan pengguna kembali ke daftar setelah operasi berhasil. */
function redirectToList($message, $pathPrefix = '../')
{
    header('Location: ' . $pathPrefix . 'index.php?message=' . rawurlencode($message));
    exit;
}
