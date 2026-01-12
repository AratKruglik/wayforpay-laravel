<?php

use AratKruglik\WayForPay\Services\SignatureGenerator;

test('it verifies correct signature', function () {
    $secretKey = 'secret';
    $generator = new SignatureGenerator($secretKey);
    $params = ['data1', 'data2'];
    $signature = $generator->generate($params);

    expect($generator->verify($params, $signature))->toBeTrue();
});

test('it fails invalid signature', function () {
    $secretKey = 'secret';
    $generator = new SignatureGenerator($secretKey);
    $params = ['data1', 'data2'];
    
    expect($generator->verify($params, 'invalid'))->toBeFalse();
});
