<?php
use Salla\ZATCA\GenerateQrCode;
use Salla\ZATCA\Tags\InvoiceDate;
use Salla\ZATCA\Tags\InvoiceTaxAmount;
use Salla\ZATCA\Tags\InvoiceTotalAmount;
use Salla\ZATCA\Tags\Seller;
use Salla\ZATCA\Tags\TaxNumber;
add_hook('ClientAreaPageViewInvoice', 1, function($vars) {

    include_once(__DIR__ . '/vendor/autoload.php');

    $generatedString = GenerateQrCode::fromArray([
                new Seller($vars['companyname']), 
                //new TaxNumber($vars['taxCode']), 
                new TaxNumber("300506966400003"),  
                new InvoiceDate(str_replace(' ','T',$vars['created_at']).'Z'), 
                new InvoiceTotalAmount((float)$vars['total']->toNumeric()),
                new InvoiceTaxAmount((float)$vars['tax']->toNumeric() + (float)$vars['tax2']->toNumeric()) 
            ])->render();

    $vars['payto'] = $vars['payto'] . '<script>window.addEventListener("DOMContentLoaded", (event) => {function insertAfter(referenceNode, newNode) {referenceNode.parentNode.insertBefore(newNode, referenceNode.nextSibling);}var divQR = document.createElement("div");divQR.innerHTML = "<img style=\"width: 150px\" src=\"'.$generatedString.'\"/>";var transactionsContainer = document.querySelector(".transactions-container");insertAfter(transactionsContainer, divQR);});</script>';

    return $vars;
    
});