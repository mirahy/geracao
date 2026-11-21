<?php

return [
    // Caminho do executável pdftotext (poppler-utils)
    'pdftotext_binary' => env('PDFTOTEXT_BINARY', 'pdftotext'),

    // Preço de referência (R$/kWh) quando a fatura não informar a tarifa
    'default_tariff' => 1.30983,
];
