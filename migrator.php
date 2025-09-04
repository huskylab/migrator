<?php
// Отключаем кэширование, буферизацию и устанавливаем лимиты
@ini_set('memory_limit', '1024M');
@ini_set('max_execution_time', 0);
set_time_limit(0);
error_reporting(E_ALL);
ini_set('display_errors', 1);
// Отключаем буферизацию вывода для логов в реальном времени
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', 1);
}
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
ob_implicit_flush(1);
while (ob_get_level() > 0) {
    ob_end_flush();
}

// =================================================================================
// КОНФИГУРАЦИЯ (!!! ВАЖНО: ЗАПОЛНИТЕ ПЕРЕД ИСПОЛЬЗОВАНИЕМ !!!)
// =================================================================================

// --- Настройки для ЭКСПОРТА (заполняются на СТАРОМ хостинге) ---
$db_config_old = [
    'host'   => 'localhost',
    'user'   => 'user',
    'pass'   => 'pass',
    'name'   => 'name',
];

// --- Настройки для ИМПОРТА и ЗАМЕНЫ (заполняются на НОВОМ хостинге) ---
$db_config_new = [
    'host'   => 'localhost',
    'user'   => 'user',
    'pass'   => 'pass',
    'name'   => 'name',, // Важно: база должна быть уже создана и пуста
];

// --- Настройки миграции ---
$export_dir = 'migration_dump'; // Папка для SQL файлов (будет создана рядом со скриптом)
$table_to_chunk = 'wp_posts';   // Таблица, которую будем делить на части
$chunk_size = 5000;             // Количество строк в одной части (рекомендую начать с 2000-5000)

// --- Настройки для ЗАМЕНЫ ДОМЕНА ---
$old_domain = 'http://example.ru';
$new_domain = 'https://пример.рф';

// Таблицы и колонки для замены. Этот массив будет сгенерирован автоматически на шаге 3.1
// Можете оставить его пустым или с дефолтными значениями.
$tables_for_replace = [
    'wp_posts' => ['post_content'],
];

// =================================================================================
// ЛОГИКА СКРИПТА (дальше можно не редактировать)
// =================================================================================

$action = $_GET['action'] ?? 'index';

// Функция для логирования с временной меткой
function log_message($message) {
    echo date('H:i:s d.m.Y') . ' - ' . $message . PHP_EOL;
    flush();
}

function get_db_connection($config) {
    $mysqli = new mysqli($config['host'], $config['user'], $config['pass'], $config['name']);
    if ($mysqli->connect_error) {
        log_message("КРИТИЧЕСКАЯ ОШИБКА: Ошибка подключения к БД: " . $mysqli->connect_error);
        die();
    }
    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}

// Рекурсивная функция для корректной замены в сериализованных данных
function recursive_unserialize_replace($old, $new, $data) {
    if (is_string($data)) {
        return str_replace($old, $new, $data);
    }
    if (is_array($data)) {
        $tmp = [];
        foreach ($data as $key => $value) {
            $tmp[$key] = recursive_unserialize_replace($old, $new, $value);
        }
        return $tmp;
    }
    if (is_object($data)) {
        $tmp = clone $data;
        foreach ($data as $key => $value) {
            $tmp->$key = recursive_unserialize_replace($old, $new, $value);
        }
        return $tmp;
    }
    return $data;
}

// Функция для проверки, является ли строка сериализованной
function is_serialized($data) {
    if (!is_string($data) || empty($data)) {
        return false;
    }
    return (@unserialize($data) !== false);
}

// =================================================================================
// HTML ИНТЕРФЕЙС
// =================================================================================
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>PHP MySQL Migrator</title>
    <style>
        body { font-family: sans-serif; line-height: 1.6; padding: 20px; max-width: 900px; margin: auto; background: #f4f4f4; }
        .container { background: #fff; padding: 20px; border-radius: 5px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h1, h2, h3 { color: #333; }
        .button { display: inline-block; padding: 10px 20px; background: #0073aa; color: #fff; text-decoration: none; border-radius: 3px; margin: 5px 0; }
        .button:hover { background: #005177; }
        .button.danger { background: #d63638; }
        .button.danger:hover { background: #b02a2c; }
        .button.secondary { background: #6c757d; }
        .button.secondary:hover { background: #5a6268; }
        .log { background: #222; color: #0f0; padding: 15px; border-radius: 3px; font-family: monospace; white-space: pre-wrap; word-wrap: break-word; max-height: 400px; overflow-y: auto; }
        .warning { background: #fff3cd; border: 1px solid #ffeeba; padding: 15px; border-radius: 3px; color: #856404; margin-bottom: 20px; }
        .code-block { background: #eef; padding: 15px; border-radius: 3px; font-family: monospace; white-space: pre; overflow-x: auto; border: 1px solid #ddd; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 14px; }
        th { background-color: #f2f2f2; }
        .diff { color: red; font-weight: bold; }
    </style>
</head>
<body>
<div class="container">
    <h1>PHP MySQL Migrator</h1>
    <div class="warning">
        <strong>ВНИМАНИЕ!</strong> После завершения всех операций **НЕМЕДЛЕННО УДАЛИТЕ ЭТОТ ФАЙЛ (`migrator.php`) И ПАПКУ С ДАМПАМИ (`<?php echo $export_dir; ?>`) С СЕРВЕРА!</strong>
    </div>

    <?php if ($action === 'index'): ?>
        <h2>Шаг 1: Экспорт (на старом хостинге)</h2>
        <p>Создание дампа базы данных в папке <code><?php echo $export_dir; ?></code>.</p>
        <a href="?action=export" class="button">Начать экспорт</a>
        <hr>
        <h2>Шаг 2: Импорт (на новом хостинге)</h2>
        <p>Загрузка дампа в новую, пустую базу данных.</p>
        <a href="?action=import" class="button">Начать импорт</a>
        <hr>
        <h2>Шаг 3: Замена домена (на новом хостинге)</h2>
        <h3>3.1 (Опционально, но рекомендуется) Автопоиск таблиц для замены</h3>
        <p>Скрипт просканирует всю базу данных и найдет все таблицы и колонки, содержащие ваш старый домен. Это может занять несколько минут. В результате будет сгенерирован PHP-код, который нужно будет вставить в этот файл.</p>
        <a href="?action=discover" class="button secondary">Запустить автопоиск и генерацию конфигурации</a>

        <h3>3.2 Предпросмотр и выполнение замены</h3>
        <p>Этот шаг использует массив <code>$tables_for_replace</code> из конфигурации скрипта. Убедитесь, что он заполнен правильно (вручную или с помощью автопоиска).</p>
        <a href="?action=replace_preview" class="button">Предпросмотр замены домена</a>
    <?php endif; ?>

    <div class="log">
        <?php
        // =========================================================================
        // ЛОГИКА ЭКСПОРТА (без изменений)
        // =========================================================================
        // =========================================================================
        // ЛОГИКА ЭКСПОРТА
        // =========================================================================
        if ($action === 'export') {
            log_message("===== НАЧАЛО ПРОЦЕССА ЭКСПОРТА =====");
            log_message("Подключаюсь к старой БД '{$db_config_old['name']}'...");
            $mysqli = get_db_connection($db_config_old);
            log_message("Подключение к БД успешно. OK");

            log_message("Проверяю/создаю директорию для дампа: '{$export_dir}'...");
            if (!is_dir($export_dir)) {
                if (mkdir($export_dir, 0755, true)) {
                    log_message("Директория '{$export_dir}' успешно создана. OK");
                } else {
                    log_message("КРИТИЧЕСКАЯ ОШИБКА: Не удалось создать директорию '{$export_dir}'. Проверьте права.");
                    die();
                }
            } else {
                log_message("Директория '{$export_dir}' уже существует.");
            }

            $tables_result = $mysqli->query("SHOW TABLES");
            log_message("Получен список таблиц из БД. Всего: " . $tables_result->num_rows);

            while ($row = $tables_result->fetch_row()) {
                $table = $row[0];
                log_message("Обработка таблицы `{$table}`...");

                // Структура таблицы
                log_message("  -> Экспорт структуры...");
                $create_table_result = $mysqli->query("SHOW CREATE TABLE `{$table}`");
                $create_table_row = $create_table_result->fetch_assoc();
                $sql = $create_table_row['Create Table'] . ";\n\n";
                $file_path_structure = "{$export_dir}/{$table}.structure.sql";
                file_put_contents($file_path_structure, $sql);
                log_message("  -> Структура сохранена в '{$file_path_structure}'. OK");

                // Данные
                if ($table == $table_to_chunk) {
                    // Делим большую таблицу на части
                    $count_result = $mysqli->query("SELECT COUNT(*) as total FROM `{$table}`");
                    $total_rows = $count_result->fetch_assoc()['total'];
                    $num_chunks = ceil($total_rows / $chunk_size);
                    log_message("  -> Таблица большая ({$total_rows} строк), делим на {$num_chunks} частей (по {$chunk_size} строк).");

                    for ($i = 0; $i < $num_chunks; $i++) {
                        $offset = $i * $chunk_size;
                        log_message("    -> Экспорт части " . ($i + 1) . " из {$num_chunks} (строки {$offset} - " . ($offset + $chunk_size) . ")...");
                        $chunk_file_path = "{$export_dir}/{$table}.data.part" . str_pad($i + 1, 4, '0', STR_PAD_LEFT) . ".sql";
                        $fp = fopen($chunk_file_path, 'w');

                        $data_result = $mysqli->query("SELECT * FROM `{$table}` LIMIT {$offset}, {$chunk_size}");
                        while ($data_row = $data_result->fetch_assoc()) {
                            $keys = array_map([$mysqli, 'real_escape_string'], array_keys($data_row));
                            $values = array_map(function($value) use ($mysqli) {
                                return $value === null ? 'NULL' : "'" . $mysqli->real_escape_string($value) . "'";
                            }, $data_row);
                            fwrite($fp, "INSERT INTO `{$table}` (`" . implode('`,`', $keys) . "`) VALUES (" . implode(',', $values) . ");\n");
                        }
                        fclose($fp);
                        log_message("    -> Часть " . ($i + 1) . " сохранена в '{$chunk_file_path}'. OK");
                    }
                } else {
                    // Экспортируем маленькие таблицы целиком
                    log_message("  -> Экспорт данных (таблица целиком)...");
                    $file_path_data = "{$export_dir}/{$table}.data.sql";
                    $fp = fopen($file_path_data, 'w');
                    $data_result = $mysqli->query("SELECT * FROM `{$table}`");
                    while ($data_row = $data_result->fetch_assoc()) {
                        $keys = array_map([$mysqli, 'real_escape_string'], array_keys($data_row));
                        $values = array_map(function($value) use ($mysqli) {
                            return $value === null ? 'NULL' : "'" . $mysqli->real_escape_string($value) . "'";
                        }, $data_row);
                        fwrite($fp, "INSERT INTO `{$table}` (`" . implode('`,`', $keys) . "`) VALUES (" . implode(',', $values) . ");\n");
                    }
                    fclose($fp);
                    log_message("  -> Данные сохранены в '{$file_path_data}'. OK");
                }
                log_message("Таблица `{$table}` полностью обработана.");
            }
            $mysqli->close();
            log_message("===== ЭКСПОРТ УСПЕШНО ЗАВЕРШЕН =====");
            log_message("Теперь скачайте эту папку `{$export_dir}` и файл `migrator.php`, загрузите их на новый хостинг и перейдите к шагу импорта.");
        }

        // =========================================================================
        // ЛОГИКА ИМПОРТА (без изменений)
        // =========================================================================
        if ($action === 'import') {
            log_message("===== НАЧАЛО ПРОЦЕССА ИМПОРТА =====");
            log_message("Подключаюсь к новой БД '{$db_config_new['name']}'...");
            $mysqli = get_db_connection($db_config_new);
            log_message("Подключение к БД успешно. OK");

            // --- ЭТАП 1: ИМПОРТ СТРУКТУРЫ ТАБЛИЦ ---
            log_message("===== ЭТАП 1: ИМПОРТ СТРУКТУРЫ ТАБЛИЦ =====");
            log_message("Сканирую папку '{$export_dir}' на наличие файлов структуры (*.structure.sql)...");
            $structure_files = glob("{$export_dir}/*.structure.sql");
            sort($structure_files);
            log_message("Найдено " . count($structure_files) . " файлов для создания структуры.");

            foreach ($structure_files as $file) {
                log_message("Импортирую структуру из файла: '{$file}'...");
                $sql_content = file_get_contents($file);
                if (!empty(trim($sql_content))) {
                    if (!$mysqli->multi_query($sql_content)) {
                        log_message("  -> ОШИБКА: " . $mysqli->error);
                    } else {
                        // Необходимо очистить результаты multi_query
                        while ($mysqli->more_results() && $mysqli->next_result()) {;}
                        log_message("  -> Структура успешно создана. OK");
                    }
                } else {
                    log_message("  -> Файл пуст, пропускаю.");
                }
            }
            log_message("===== ЭТАП 1 ЗАВЕРШЕН. Все структуры таблиц созданы. =====");

            // --- ЭТАП 2: ИМПОРТ ДАННЫХ ---
            log_message("===== ЭТАП 2: ИМПОРТ ДАННЫХ В ТАБЛИЦЫ =====");
            log_message("Сканирую папку '{$export_dir}' на наличие файлов с данными (*.data.sql и *.data.part*.sql)...");
            $data_files = glob("{$export_dir}/*.data*.sql");
            sort($data_files);
            log_message("Найдено " . count($data_files) . " файлов с данными.");

            foreach ($data_files as $file) {
                log_message("Импортирую данные из файла: '{$file}'...");

                // Для файлов с данными лучше выполнять запросы построчно, чтобы не упереться в лимиты памяти
                $file_handle = fopen($file, "r");
                if ($file_handle) {
                    $query_count = 0;
                    while (($line = fgets($file_handle)) !== false) {
                        $query = trim($line);
                        if (!empty($query) && substr($query, 0, 2) !== '--') {
                            if (!$mysqli->query($query)) {
                                log_message("  -> ОШИБКА: " . $mysqli->error);
                                log_message("  -> ЗАПРОС: " . substr($query, 0, 250) . "...");
                            }
                            $query_count++;
                        }
                    }
                    fclose($file_handle);
                    log_message("  -> Обработано {$query_count} запросов. OK");
                } else {
                    log_message("  -> ОШИБКА: Не удалось открыть файл '{$file}'.");
                }
            }

            $mysqli->close();
            log_message("===== ЭТАП 2 ЗАВЕРШЕН. Все данные импортированы. =====");
            log_message("===== ИМПОРТ УСПЕШНО ЗАВЕРШЕН =====");
            log_message("Теперь вы можете перейти к шагу замены домена.");
        }

        // =========================================================================
        // НОВЫЙ РЕЖИМ: АВТОПОИСК ТАБЛИЦ
        // =========================================================================
                if ($action === 'discover') {
            log_message("===== НАЧАЛО АВТОПОИСКА ТАБЛИЦ ДЛЯ ЗАМЕНЫ =====");
            log_message("Сканирование может занять несколько минут. Не закрывайте страницу.");
            log_message("Ищем вхождения строки: '{$old_domain}'");

            $mysqli = get_db_connection($db_config_new);
            $all_tables_result = $mysqli->query("SHOW TABLES");

            $found_map = [];
            $table_count = $all_tables_result->num_rows;
            $current_table_num = 0;

            while ($table_row = $all_tables_result->fetch_row()) {
                $table_name = $table_row[0];
                $current_table_num++;
                log_message("[{$current_table_num}/{$table_count}] Сканирую таблицу `{$table_name}`...");

                $columns_result = $mysqli->query("SHOW COLUMNS FROM `{$table_name}`");
                while ($column_row = $columns_result->fetch_assoc()) {
                    $column_name = $column_row['Field'];
                    $column_type = strtolower($column_row['Type']);

                    if (strpos($column_type, 'char') !== false || strpos($column_type, 'text') !== false) {
                        $query = "SELECT 1 FROM `{$table_name}` WHERE `{$column_name}` LIKE '%" . $mysqli->real_escape_string($old_domain) . "%' LIMIT 1";
                        $check_result = $mysqli->query($query);
                        if ($check_result && $check_result->num_rows > 0) {
                            log_message("  -> НАЙДЕНО вхождение в колонке `{$column_name}`. Добавляю в список.");
                            if (!isset($found_map[$table_name])) {
                                $found_map[$table_name] = [];
                            }
                            $found_map[$table_name][] = $column_name;
                        }
                    }
                }
            
            }

            log_message("===== АВТОПОИСК ЗАВЕРШЕН =====");
            echo "</div>"; // Закрываем черный лог

            if (empty($found_map)) {
                echo "<h3>Результат:</h3><p>Вхождения старого домена '{$old_domain}' не найдены в базе данных. Возможно, замена не требуется.</p>";
            } else {
                echo "<h3>Результат:</h3><p>Найдены вхождения в следующих таблицах. Скопируйте приведенный ниже PHP-код и **полностью замените** им переменную <code>\$tables_for_replace</code> в файле <code>migrator.php</code>.</p>";

                $generated_code = "\$tables_for_replace = [\n";
                foreach ($found_map as $table => $columns) {
                    $columns_str = "'" . implode("', '", $columns) . "'";
                    $generated_code .= "    '{$table}' => [{$columns_str}],\n";
                }
                $generated_code .= "];";

                echo '<div class="code-block">' . htmlspecialchars($generated_code) . '</div>';
                echo "<p>После того, как вы обновите файл <code>migrator.php</code>, вернитесь на главную страницу и запустите 'Предпросмотр замены домена'.</p>";
            }

            echo '<div class="log">'; // Открываем лог обратно для консистентности
            $mysqli->close();
        }

        // =========================================================================
        // ЛОГИКА ЗАМЕНЫ ДОМЕНА (ПРЕДПРОСМОТР)
        // =========================================================================
        if ($action === 'replace_preview') {
            echo "</div>"; // Закрываем лог, чтобы вывести таблицу
            echo "<h2>Предпросмотр замены домена</h2>";
            echo "<p>Анализ базы данных для замены <code>" . htmlspecialchars($old_domain) . "</code> на <code>" . htmlspecialchars($new_domain) . "</code></p>";

            $mysqli = get_db_connection($db_config_new);
            $found = false;

            echo "<table><tr><th>Таблица</th><th>Колонка</th><th>ID</th><th>Пример изменения</th></tr>";

            foreach ($tables_for_replace as $table => $columns) {
                $pk_res = $mysqli->query("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");
                if (!$pk_res || $pk_res->num_rows == 0) {
                    echo "<tr><td colspan='4'>Не удалось найти первичный ключ для таблицы `{$table}`. Пропускаем.</td></tr>";
                    continue;
                }
                $pk_col = $pk_res->fetch_assoc()['Column_name'];

                foreach ($columns as $column) {
                    $query = "SELECT `{$pk_col}`, `{$column}` FROM `{$table}` WHERE `{$column}` LIKE '%" . $mysqli->real_escape_string(str_replace(['http://', 'https://'], '', $old_domain)) . "%' LIMIT 5";
                    $result = $mysqli->query($query);

                    if ($result && $result->num_rows > 0) {
                        $found = true;
                        while ($row = $result->fetch_assoc()) {
                            $old_value = $row[$column];
                            $new_value = '';

                            if (is_serialized($old_value)) {
                                $unserialized_data = unserialize($old_value);
                                $new_unserialized_data = recursive_unserialize_replace($old_domain, $new_domain, $unserialized_data);
                                $new_value = serialize($new_unserialized_data);
                            } else {
                                $new_value = str_replace($old_domain, $new_domain, $old_value);
                            }

                            $old_display = htmlspecialchars(substr($old_value, 0, 150)) . '...';
                            $new_display = str_replace(
                                    htmlspecialchars($old_domain),
                                    '<span class="diff">' . htmlspecialchars($new_domain) . '</span>',
                                    htmlspecialchars(substr($new_value, 0, 150))
                                ) . '...';

                            echo "<tr>";
                            echo "<td>{$table}</td>";
                            echo "<td>{$column}</td>";
                            echo "<td>{$row[$pk_col]}</td>";
                            echo "<td><small><b>Было:</b> {$old_display}<br><b>Станет:</b> {$new_display}</small></td>";
                            echo "</tr>";
                        }
                    }
                }
            }
            echo "</table>";

            if (!$found) {
                echo "<div class='log'>Вхождения старого домена не найдены. Возможно, замена не требуется или уже была выполнена.</div>";
            } else {
                echo "<h2>Подтверждение</h2>";
                echo "<p>Выше показаны примеры изменений. Если все корректно, нажмите кнопку ниже, чтобы выполнить замену во всей базе данных.</p>";
                echo '<a href="?action=replace_execute" class="button danger">ВЫПОЛНИТЬ ЗАМЕНУ</a>';
            }
            echo "<div class='log'>"; // Открываем обратно для последующих логов
            $mysqli->close();
        }
        // =========================================================================
        // ЛОГИКА ЗАМЕНЫ ДОМЕНА (ИСПОЛНЕНИЕ)
        // =========================================================================
        if ($action === 'replace_execute') {
            log_message("===== НАЧАЛО ПРОЦЕССА ЗАМЕНЫ ДОМЕНА =====");
            log_message("ЗАПУСК ПРОЦЕССА ЗАМЕНЫ. НЕ ЗАКРЫВАЙТЕ ЭТУ СТРАНИЦУ!");
            log_message("Размер порции для обработки: {$replace_chunk_size} строк.");

            $mysqli = get_db_connection($db_config_new);

            foreach ($tables_for_replace as $table => $columns) {
                $pk_res = $mysqli->query("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");
                if (!$pk_res || $pk_res->num_rows == 0) {
                    log_message("-> Не найден первичный ключ для `{$table}`. Пропускаем.");
                    continue;
                }
                $pk_col = $pk_res->fetch_assoc()['Column_name'];

                foreach ($columns as $column) {
                    log_message("-> Обработка `{$table}`.`{$column}`...");

                    // 1. Простая замена для несериализованных данных (быстро и эффективно)
                    $update_query_simple = "UPDATE `{$table}` SET `{$column}` = REPLACE(`{$column}`, '" . $mysqli->real_escape_string($old_domain) . "', '" . $mysqli->real_escape_string($new_domain) . "')";
                    $mysqli->query($update_query_simple);
                    log_message("  -> Простая замена выполнена (затронуто {$mysqli->affected_rows} строк).");

                    // 2. Обработка сериализованных данных частями, чтобы избежать переполнения памяти
                    log_message("  -> Поиск и обработка сериализованных данных...");
                    $offset = 0;
                    $processed_serialized_total = 0;

                    while (true) {
                        $select_serialized = "SELECT `{$pk_col}`, `{$column}` FROM `{$table}` WHERE `{$column}` LIKE '%s:%' LIMIT {$offset}, {$replace_chunk_size}";
                        $result_serialized = $mysqli->query($select_serialized);

                        if (!$result_serialized || $result_serialized->num_rows === 0) {
                            // Больше нет строк для обработки, выходим из цикла
                            break;
                        }

                        $rows_in_chunk = $result_serialized->num_rows;
                        log_message("    -> Обрабатываю порцию строк " . ($offset + 1) . " - " . ($offset + $rows_in_chunk));

                        $processed_in_chunk = 0;
                        while ($row = $result_serialized->fetch_assoc()) {
                            $original_data = $row[$column];
                            if(is_serialized($original_data)) {
                                $unserialized = unserialize($original_data);
                                $replaced_data = recursive_unserialize_replace($old_domain, $new_domain, $unserialized);
                                $new_serialized_data = serialize($replaced_data);

                                if ($original_data !== $new_serialized_data) {
                                    $update_query = "UPDATE `{$table}` SET `{$column}` = '" . $mysqli->real_escape_string($new_serialized_data) . "' WHERE `{$pk_col}` = '" . $mysqli->real_escape_string($row[$pk_col]) . "'";
                                    $mysqli->query($update_query);
                                    $processed_in_chunk++;
                                }
                            }
                        }

                        $processed_serialized_total += $processed_in_chunk;
                        log_message("      -> Обновлено {$processed_in_chunk} строк в этой порции.");

                        $offset += $replace_chunk_size;
                    }

                    log_message("  -> Корректная замена для сериализованных данных завершена (всего обновлено {$processed_serialized_total} строк).");
                }
            }

            $mysqli->close();
            log_message("===== ЗАМЕНА ДОМЕНА УСПЕШНО ЗАВЕРШЕНА =====");
            log_message("!!! КРАЙНЕ ВАЖНО: ПРЯМО СЕЙЧАС УДАЛИТЕ ФАЙЛ `migrator.php` И ПАПКУ `{$export_dir}` С СЕРВЕРА !!!");
        }
        ?>
    </div>
</div>
</body>
</html>
