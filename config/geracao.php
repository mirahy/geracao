<?php

return [
    // Caminho do executável pdftotext (poppler-utils)
    'pdftotext_binary' => env('PDFTOTEXT_BINARY', 'pdftotext'),

    // OCR opcional dos comprovantes em imagem: caminho do executável tesseract (vazio = desligado,
    // o usuário digita os dados), pasta dos idiomas (TESSDATA_PREFIX) e idioma.
    'tesseract_binary' => env('TESSERACT_BINARY'),
    'tessdata_dir' => env('TESSDATA_DIR'),
    'ocr_language' => env('OCR_LANGUAGE', 'por'),

    // Preço de referência (R$/kWh) quando a fatura não informar a tarifa
    'default_tariff' => 1.30983,
];
