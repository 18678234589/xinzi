<?php

        $csvData = trim($_POST['csv_data'] ?? '');
        $hasFile = isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === UPLOAD_ERR_OK;

