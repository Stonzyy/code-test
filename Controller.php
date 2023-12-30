<?php
/**
 * @copyright Copyright (c) KUWAITNET Co. 2020
 * @license https://kuwaitnet.com
 */

namespace WHMCS\Module\Addon\Kwupload\Client;

use WHMCS\Database\Capsule;

/**
 * Kwupload Client Area Controller
 */
class Controller {

    /**
     * Index action.
     *
     * @param array $vars Module configuration parameters
     *
     * @return array
     */
    public function index($vars)
    {
        $registrarMapping = json_decode($vars['domain_map']);
        if (empty($vars['upload_url']) || empty($vars['authentication_token']) || empty($vars['registrar_id'])) {
            $errormsg[] = 'Configuration are not done.';
            $returnArray['vars']['error'] = true;
            $returnArray['vars']['errormsg'] = $errormsg;
            return $returnArray;
        }
        $uploadUrl = $vars['upload_url'];
        $authenticationToken = $vars['authentication_token'];
        $registrarId = $vars['registrar_id'];
        $uploadNames = [];

        $allowed_file_extension = array(
            "png",
            "jpg",
            "jpeg",
            "pdf"
        );

        if (!(isset($_SESSION['uid']) && intval($_SESSION['uid'])!==0)) {
            header("Location: ".$system_url."/clientarea.php");
        }
        $modulelink = $vars['modulelink'];
        $domainCartId = array_search($_REQUEST['domain'],array_column($_SESSION['cart']['domains'], 'domain'));

        if (!$domainCartId &&  $domainCartId !== 0) {
            if (isset($_REQUEST['cartid'])) {
                $domainCartId = $_REQUEST['cartid'];
            } else {
                header("Location: ".$system_url."cart.php?a=confdomains"); die();
            }
        }
        $domainName = ($_REQUEST['domain']) ? $_REQUEST['domain'] : $_REQUEST['domainName'];
        $domainSuffix = substr($domainName, strpos($domainName, "."));

        if(!$registrarMapping->{$domainSuffix}->{'fields'} || !empty($_SESSION['cart']['domains'][$domainCartId]['kwverified'])) {
            header("Location: ".$system_url."cart.php?a=confdomains"); die();
        }
        $wathqSaFlag = 1;
        $wathqFlag = $_SESSION['cart']['wathq'];
        $iscomsa = false;
        if ($domainSuffix == '.sa' || $domainSuffix == '.السعودية') {
            $wathqFlag = $_SESSION['cart']['wathqsa'];
            $wathqSaFlag = $_SESSION['cart']['wathqsa'];
        } elseif ($domainSuffix == '.com.sa') {
            $iscomsa = true;
            $wathqFlag = $_SESSION['cart']['wathq'];
        }

        $returnArray = array(
            'breadcrumb' => false,
            'templatefile' => 'publicpage',
            'requirelogin' => true,
            'forcessl' => true,
            'vars' => array(
                'modulelink' => $modulelink,
                'cartIds'=> false,
                'cartid' => $domainCartId,
                'domainName' => $domainName,
                'uploadfields' => $registrarMapping->{$domainSuffix}->{'fields'},
                'wathq' => $wathqFlag,
                'saindividual' => $_SESSION['cart']['saindividual'],
                'iscomsa' => $iscomsa,
                'wathqsa' => $wathqSaFlag,
                'iscommercial' => $_SESSION['cart']['wathq'],
                'otp' => $_SESSION['cart']['otpverifed'],
                'mailotp' => $_SESSION['cart']['mailotpverifed'],
                'noPrefix' => $vars['no_prefix'],
                'error' => false
            ),
        );
        if (isset($_REQUEST['multiple']) && $_REQUEST['multiple'] == '1') {
            $returnArray['vars']['cartIds'] = true;
        }

        $url  = Capsule::table('tblconfiguration')->where('setting','=','SystemURL')->first();
        $system_url = $url->value;
        $errormsg = [];

        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            foreach ($registrarMapping->{$domainSuffix}->{'fields'} as $key => $field) {
                if ($field->id == 'national_id' && ((isset($_SESSION['cart']['wathq']) && $_SESSION['cart']['wathq'] == 1) || !empty($_REQUEST['supporting_document']))) {
                    continue;
                }
                if ($field->id == 'supportingdocument' && (isset($_SESSION['cart']['saindividual']) && $_SESSION['cart']['saindividual'] == 1) && !$iscomsa) {
                    continue;
                } elseif ($field->id == 'supportingdocument' && (isset($_REQUEST['wathqsa']) && $_REQUEST['wathqsa'] == 'Individual') && !$iscomsa) {
                    continue;
                }
                $uploadNames[$field->id]["label"] = $field->label;
                $uploadNames[$field->id]["label_ar"] = $field->label_ar;
                $uploadNames[$field->id]["document_type"] = $field->document_type;
                if ($field->isrequired && empty($_FILES[$field->id]['name'])) {
                    $errormsg[] = ($_REQUEST['lang'] == 'arabic') ? 'الرجاء تحميل الحقول المطلوبة'.$field->label : 'Please upload the required fields.'.$field->label;
                    $returnArray['vars']['error'] = true;
                    $returnArray['vars']['errormsg'] = $errormsg;
                    return $returnArray;
                }
                $commonRequiredFieldMsg = ($_REQUEST['lang'] == 'arabic') ? 'الرجاء ادخال الحقول المطلوبة' : 'Please fill the required fields.';
                if ($field->id == 'supportingdocument' && empty($_REQUEST['supporting_document'])) {
                    $errormsg[] = $commonRequiredFieldMsg;
                    $returnArray['vars']['error'] = true;
                    $returnArray['vars']['errormsg'] = $errormsg;
                    return $returnArray;
                }
                if ($field->id == 'supportingdocument' && $_REQUEST['supporting_document'] == 'commercial_registration') {
                    continue;
                }
                if ($field->isrequired && $field->textarea &&  empty($_REQUEST[$field->id.'_explanation'])) {
                    $errormsg[] = $commonRequiredFieldMsg;
                    $returnArray['vars']['error'] = true;
                    $returnArray['vars']['errormsg'] = $errormsg;
                    return $returnArray;
                } elseif ($field->isrequired && $field->documentid && empty($_REQUEST[$field->id.'_documnetid'])) {
                    $errormsg[] = $commonRequiredFieldMsg;
                    $returnArray['vars']['error'] = true;
                    $returnArray['vars']['errormsg'] = $errormsg;
                    return $returnArray;
                } elseif ($field->isrequired && $field->issuer_menu && empty($_REQUEST[$field->id.'_issuer'])) {
                    $errormsg[] = $commonRequiredFieldMsg;
                    $returnArray['vars']['error'] = true;
                    $returnArray['vars']['errormsg'] = $errormsg;
                    return $returnArray;
                } elseif ($_REQUEST[$field->id.'_issuer'] == 'other' && empty($_REQUEST[$field->id.'_issuername'])) {
                    $errormsg[] = $commonRequiredFieldMsg;
                    $returnArray['vars']['error'] = true;
                    $returnArray['vars']['errormsg'] = $errormsg;
                    return $returnArray;
                }

            }
            
            if (empty($_REQUEST['admincontact']['contact_name']) || empty($_REQUEST['admincontact']['contact_org']) || empty($_REQUEST['admincontact']['contact_city']) || empty($_REQUEST['admincontact']['contact_cc'])) {
                $errormsg[] = ($_REQUEST['lang'] == 'arabic') ? 'يرجى ادخال الحقول المطلوبة للمنسق الإداري.' : 'Please fill the required Admin Contact fields.';
                $returnArray['vars']['error'] = true;
                $returnArray['vars']['errormsg'] = $errormsg;
                return $returnArray;
            }
            if (!empty($_REQUEST['admincontact']['contact_voice']) && !preg_match("/^01[0-9]{8}$/", $_REQUEST['admincontact']['contact_voice'])) {
                $errormsg[] = ($_REQUEST['lang'] == 'arabic') ? 'يجب أن يكون تنسيق رقم الهاتف مثل , 01X1234567' : 'Phone number format must be like, 01X1234567';
                $returnArray['vars']['error'] = true;
                $returnArray['vars']['errormsg'] = $errormsg;
                return $returnArray;
            } elseif (!empty($_REQUEST['admincontact']['contact_fax']) && !preg_match("/^01[0-9]{8}$/", $_REQUEST['admincontact']['contact_fax'])) {
                $errormsg[] = ($_REQUEST['lang'] == 'arabic') ? 'يجب أن يكون تنسيق رقم الفاكس مثل , 01X1234567' : 'Fax format must be like, 01X1234567';
                $returnArray['vars']['error'] = true;
                $returnArray['vars']['errormsg'] = $errormsg;
                return $returnArray;
            }

            foreach ($_FILES as $key => $value) {
                if (!empty($_FILES[$key]['name'])) {
                    $file_extension = pathinfo($_FILES[$key]["name"], PATHINFO_EXTENSION);
                    if (!in_array($file_extension, $allowed_file_extension)) {
                        $errormsg[] = ($_REQUEST['lang'] == 'arabic') ? 'نوع الملف غير صحيح يرجى تحميل الصيغة الصحيحة' : 'Invaild type. Please upload the correctly.';
                        $returnArray['vars']['error'] = true;
                        $returnArray['vars']['errormsg'] = $errormsg;
                        return $returnArray;
                    }
                    if (($_FILES[$key]["size"] > 4000000)) {
                        $errormsg[] = ($_REQUEST['lang'] == 'arabic') ? 'الحجم يتجاوز 4 ميغا بايت. يرجى تحميل ملف أصغر.' : 'Invaild type. Please upload the correctly.';
                        $returnArray['vars']['error'] = true;
                        $returnArray['vars']['errormsg'] = $errormsg;
                        return $returnArray;
                    }
                }
            }

            if (isset($_SESSION['uid']) && intval($_SESSION['uid'])!==0) {
                $uid = $_SESSION['uid'];
                $ch = curl_init();
                $command = 'GetClientsDetails';
                $postData = array(
                    'clientid' => $_SESSION['uid'],
                    'stats' => false,
                );
                $results = localAPI($command, $postData);
                $email = $results['email'];
                $files = [];
                $storefiles = [];
                foreach ($_FILES as $key => $value) {
                    if (!empty($_FILES[$key]['name'])) {
                        $files[$uploadNames[$key]['label']] = curl_file_create(
                            $_FILES[$key]['tmp_name'],
                            $_FILES[$key]['type'],
                            $_FILES[$key]['name']
                        );
                        $documentId = $_REQUEST[$key.'_documnetid'];
                        $issuer = $_REQUEST[$key.'_issuer'];
                        $issuerName = $_REQUEST[$key.'_issuername'];
                        $documentType = $uploadNames[$key]['document_type'];
                        if ($documentType == 'other') {
                            $documentId = (empty($documentId)) ? 'other' : $documentId;
                            $issuer = (empty($issuer)) ? 'other' : $issuer;
                            $issuerName = (empty($issuerName)) ? 'other' : $issuerName;
                        } elseif ($key == 'supportingdocument') {
                            $documentType = $_REQUEST['supporting_document'];
                            if ($documentType == 'commercial_registration') {
                                $documentId = $_SESSION['cart']['wathqcrId'];
                                $issuer = '1';
                                $issuerName = 'Ministry of Commerce and Investment';
                            } elseif ($documentType == 'national_id') {
                                $issuer = 'MOI';
                                $issuerName = 'Ministry of Interior';
                            }
                        }

                        $storefiles[] = [
                            'document_name' => $uploadNames[$key]['label'],
                            'document_type' => $documentType,
                            'filename' => $_FILES[$key]['name'],
                            'fileext' => ".".end((explode(".", $_FILES[$key]['name']))),
                            'data' => base64_encode(file_get_contents($_FILES[$key]['tmp_name'])),
                            'document_name_ar' => $uploadNames[$key]['label_ar'],
                            'support_document_details' => $_REQUEST[$key.'_explanation'],
                            'document_id' => $documentId,
                            'issuer' => ($key == 'national_id') ? 'MOI' : $issuer,
                            'issuer_name' => ($key == 'national_id') ? 'Ministry of Interior' : $issuerName,
                            'file' => file_get_contents($_FILES[$key]['tmp_name'])
                        ];
                    }
                }
                $domainData = [
                    'registrant_email' => $email
                ];
                $domain = $_SESSION['cart']['domains'][$_REQUEST['cartid']]['domain'];
                $domainData['domain'] = $domain;
                $domainData['registrar'] = $registrarId;
                $postData = $domainData + $files;
                foreach ($storefiles as $storefile) {
                    try {
                        $pdo = Capsule::connection()->getPdo();
                        $pdo->beginTransaction();
                        $statement = $pdo->prepare(
                            'insert into mod_kwupload (document_name, document_type, filename, fileext, data, domain, document_name_ar, support_document_details, document_id, issuer, issuer_name, file) values (:document_name, :document_type, :filename, :fileext , :data, :domain, :document_name_ar, :support_document_details, :document_id, :issuer, :issuer_name, :file)'
                        );

                        $statement->execute(
                           [
                                ':document_name' => $storefile['document_name'],
                                ':document_type' => $storefile['document_type'],
                                ':filename' => $storefile['filename'],
                                ':fileext' => $storefile['fileext'],
                                ':data' => $storefile['data'],
                                ':domain' => $domain,
                                ':document_name_ar' => $storefile['document_name_ar'],
                                ':support_document_details' => $storefile['support_document_details'],
                                ':document_id' => $storefile['document_id'],
                                ':issuer' => $storefile['issuer'],
                                ':issuer_name' => $storefile['issuer_name'],
                                ':file' => $storefile['file']
                            ]
                        );

                        $pdo->commit();
                    } catch (\Exception $e) {
                        logModuleCall('kwupload', 'storeupload', 'error', $e->getMessage(), '', '');
                        $pdo->rollBack();
                    }
                }
                try {
                    $pdo = Capsule::connection()->getPdo();
                    $pdo->beginTransaction();
                    $statement = $pdo->prepare(
                        'insert into admin_contact_verify (domain_name, contact_name, contact_org, snic_position, contact_street, contact_street_1, contact_fax, snic_website, contact_city, contact_pc, contact_sp, contact_cc, snic_mobile, contact_email, contact_voice, contact_voice_x) values (:domain_name, :contact_name, :contact_org, :snic_position , :contact_street, :contact_street_1, :contact_fax, :snic_website, :contact_city, :contact_pc, :contact_sp, :contact_cc, :snic_mobile, :contact_email, :contact_voice, :contact_voice_x)'
                    );

                    $statement->execute(
                       [
                            ':domain_name' => $domain,
                            ':contact_name' => $_REQUEST['admincontact']['contact_name'],
                            ':contact_org' => $_REQUEST['admincontact']['contact_org'],
                            ':snic_position' => $_REQUEST['admincontact']['snic_position'],
                            ':contact_street' => $_REQUEST['admincontact']['contact_street'],
                            ':contact_street_1' => $_REQUEST['admincontact']['contact_street_1'],
                            ':contact_fax' => (!empty($_REQUEST['admincontact']['contact_fax'])) ? $vars['no_prefix'].'.'.ltrim($_REQUEST['admincontact']['contact_fax'], "0") : '',
                            ':snic_website' => $_REQUEST['admincontact']['snic_website'],
                            ':contact_city' => $_REQUEST['admincontact']['contact_city'],
                            ':contact_pc' => $_REQUEST['admincontact']['contact_pc'],
                            ':contact_sp' => $_REQUEST['admincontact']['contact_sp'],
                            ':contact_cc' => $_REQUEST['admincontact']['contact_cc'],
                            ':snic_mobile' => $vars['no_prefix'].'.'.ltrim(key($_SESSION['cart']['otp']), "0"),
                            ':contact_email' => key($_SESSION['cart']['mailotp']),
                            ':contact_voice' => $vars['no_prefix'].'.'.ltrim($_REQUEST['admincontact']['contact_voice'], "0"),
                            ':contact_voice_x' => $_REQUEST['admincontact']['contact_voice_x']
                        ]
                    );

                    $pdo->commit();
                } catch (\Exception $e) {
                    logModuleCall('kwupload', 'storeAdminContact', 'error', $e->getMessage(), '', '');
                    $pdo->rollBack();
                }
                $curlResponse = $this->curlReq($postData, $domainCartId, $uploadUrl, $authenticationToken, $_REQUEST['lang']);
                if ($curlResponse['error'] == false ) {
                    if ($_REQUEST['multiple'] == '1') {
                        header("Location: ".$system_url."index.php?m=kwupload&action=kwcheckout"); die();
                    } else {
                        header("Location: ".$system_url."cart.php?a=confdomains"); die();
                    }
                } else {
                    $returnArray['vars']['error'] = true;
                    $returnArray['vars']['errormsg'] = $curlResponse['errormsg'];
                    return $returnArray;
                }
            }
        } elseif (isset($_REQUEST['domain']) && !empty($_REQUEST['domain']) && !empty($_SESSION['cart'])) {
            return $returnArray;
        } else {
            header("Location: ".$system_url."cart.php?a=confdomains"); die();
        }
    }
    private function curlReq($postData, $domainCartId, $uploadUrl, $auth, $lang)
    {
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $uploadUrl."new/",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => array(
                "authorization: Token ".$auth,
                "cache-control: no-cache",
                "content-type: multipart/form-data;"
            ),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);
        $error = false;
        if ($err) {
            logActivity($err, 0);
            $error = true;
            $errormsg[] = ($lang == 'arabic') ? "حدث خطأ ،، الرجاء المحاولة في وقت لاحق" : "Something went wrong. Please try again later.";
        } else {
            $response = json_decode($response);
            if (property_exists($response, 'pk')) {
                $error = false;
                $_SESSION['cart']['domains'][$domainCartId]['kwverified'] = 1;
                unset($_SESSION['cart']['otpverifed']);
                unset($_SESSION['cart']['otp']);
                unset($_SESSION['cart']['mailotpverifed']);
                unset($_SESSION['cart']['mailotp']);
            } elseif (property_exists($response, 'domain') && is_array($response->domain)) {
                $error = true;
                $errormsg[] = $response->domain['0'];
            }
            elseif (property_exists($response, 'registrar') && is_array($response->registrar)) {
                $error = true;
                $errormsg[] = $response->registrar['0'];
            }
        }
        return  ['error' => $error, 'errormsg' => $errormsg];
    }

    public function kwcheckout($vars)
    {
        if (!(isset($_SESSION['uid']) && intval($_SESSION['uid'])!==0)) {
            header("Location: ".$system_url."/clientarea.php");
        }
        $registrarMapping = json_decode($vars['domain_map']);
        $modulelink = $vars['modulelink']; 
        if (isset($_SESSION['cart']['domains']) && !empty($_SESSION['cart']['domains'])) {
            $uploadDomain = [];
            $iscomsa = false;
            $issa = false;
            $wathqSaFlag = 1;
            $wathqFlag = $_SESSION['cart']['wathq'];
            $isRegisterType = false;
            foreach($_SESSION['cart']['domains'] as $key => $value) {
                if ($value['type'] != 'register') continue;
                $isRegisterType = true;
                if (!isset($value['kwverified']) || $value['kwverified'] != 1) {
                    $domainSuffix = substr($value['domain'], strpos($value['domain'], "."));
                    if ($domainSuffix == '.com.sa') {
                        $iscomsa = true;
                        $wathqFlag = $_SESSION['cart']['wathq'];
                    } elseif ($domainSuffix == '.sa' || $domainSuffix == '.السعودية') {
                        $issa = true;
                        $wathqFlag = $_SESSION['cart']['wathqsa'];
                        $wathqSaFlag = $_SESSION['cart']['wathqsa'];
                    }
                    if($registrarMapping->{$domainSuffix}->{'fields'}){
                        return array(
                            'breadcrumb' => false,
                            'templatefile' => 'publicpage',
                            'requirelogin' => true,
                            'forcessl' => true,
                            'vars' => array(
                                'modulelink' => $modulelink,
                                'cartIds'=> true,
                                'cartid' => $key,
                                'domainName' => $value['domain'],
                                'uploadfields' => $registrarMapping->{$domainSuffix}->{'fields'},
                                'saindividual' => $_SESSION['cart']['saindividual'],
                                'iscomsa' => $iscomsa,
                                'wathq' => $wathqFlag,
                                'iscommercial' => $_SESSION['cart']['wathq'],
                                'wathqsa' => $wathqSaFlag,
                                'otp' => $_SESSION['cart']['otpverifed'],
                                'mailotp' => $_SESSION['cart']['mailotpverifed'],
                                'noPrefix' => $vars['no_prefix'],
                                'error' => false
                            ),
                        );
                    } else {
                        $_SESSION['cart']['domains'][$key]['kwverified'] = 1;
                    }
                }
            }
            if (false) {
                if ($iscomsa) {
                    $wathqFlag = $_SESSION['cart']['wathq'];
                } elseif ($issa) {
                    $wathqFlag = $_SESSION['cart']['wathqsa'];
                    $wathqSaFlag = $_SESSION['cart']['wathqsa'];
                }

                return array (
                            'breadcrumb' => false,
                            'templatefile' => 'verify',
                            'requirelogin' => true,
                            'vars' => array(
                                'noPrefix' => $vars['no_prefix'],
                                'wathq' => $wathqFlag,
                                'wathqsa' => $wathqSaFlag,
                                'otp' => $_SESSION['cart']['otpverifed'],
                                'mailotp' => $_SESSION['cart']['mailotpverifed']
                            ),
                        );
            }
            header("Location: ".$system_url."cart.php?a=checkout"); die();
        } else {
            header("Location: ".$system_url."cart.php?a=checkout"); die();
        }
    }
    public function commercialSaIndividual($vars)
    {
        $_SESSION['cart']['wathqsa'] = 1;
        $_SESSION['cart']['saindividual'] = 1;
        $resultResponse = [
            'success' => true,
            'redirect' => $this->islicenseverified(),
            'redirecturl' => $system_url."cart.php?a=checkout"
        ];
        echo json_encode($resultResponse); exit();
    }

    public function commercialverify($vars)
    {
        $resultResponse = ['success'=> false];
	// Allow temporarily
 	$resultResponse['message'] = "Rate limit quota violation. Quota limit exceeded";
        $_SESSION['cart']['wathqcrId'] = $_REQUEST['commercialnumber'];
        $_SESSION['cart']['wathq'] = 1;
        $_SESSION['cart']['wathqsa'] = 1;
        echo json_encode($resultResponse); exit();
	// Allow temporarily
        $resultResponse['message'] = ($_REQUEST['lang'] == 'arabic') ? 'حدث خطأ ،، الرجاء المحاولة في وقت لاحق' : 'Something went wrong. Please try again later.';
        try {
            if (empty($vars['wathq_url']) || empty($vars['wathq_key'])) {
                $resultResponse['message'] = ($_REQUEST['lang'] == 'arabic') ? 'حدث خطأ.' : 'Something went wrong.';
                $resultResponse['trace'] = 'Configuration are not done.';
                echo json_encode($resultResponse); exit();
            }
            $curl = curl_init();
            if (!empty($_REQUEST['commercialnumber'])) {
                curl_setopt_array($curl, array(
                  CURLOPT_URL => $vars['wathq_url'].$_REQUEST['commercialnumber'],
                  CURLOPT_RETURNTRANSFER => true,
                  CURLOPT_ENCODING => "",
                  CURLOPT_MAXREDIRS => 10,
                  CURLOPT_TIMEOUT => 30,
                  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                  CURLOPT_CUSTOMREQUEST => "GET",
                  CURLOPT_HTTPHEADER => array(
                    "apikey: ".$vars['wathq_key'],
                    "cache-control: no-cache"
                  ),
                ));

                $response = curl_exec($curl);
                $err = curl_error($curl);

                curl_close($curl);
                if ($err) {
                    $resultResponse['trace'] = $err;
                    logModuleCall('kwupload', 'wathq', $vars['wathq_url'].$_REQUEST['commercialnumber'], $err, '', '');
                } else {
                    logModuleCall('kwupload', 'wathq', $vars['wathq_url'].$_REQUEST['commercialnumber'], $response, '', '');
                    if ($json = json_decode($response)) {
                        if (isset($json->message)) {
                            $resultResponse['message'] = $json->message;
                            if ($json->message == "Rate limit quota violation. Quota limit exceeded") {
                                $_SESSION['cart']['wathqcrId'] = $_REQUEST['commercialnumber'];
                                $_SESSION['cart']['wathq'] = 1;
                                $_SESSION['cart']['wathqsa'] = 1;
                            }
                        } elseif ($json->status->id == 'active') {
                            $_SESSION['cart']['wathqcrName'] = $json->crName;
                            $_SESSION['cart']['wathqcrId'] = $_REQUEST['commercialnumber'];
                            $resultResponse = [
                                'success' => true,
                                'redirect' => $this->islicenseverified(),
                                'redirecturl' => $system_url."cart.php?a=checkout",
                                'companyname' => $json->crName
                            ];
                        } elseif ($json->status->id) {
                            $resultResponse['message'] = ($_REQUEST['lang'] == 'arabic') ? 'لا يمكن التسجيل بإستخدام السجل التجاري هذا.' : 'Registration not allowed to use this Commercial Registration number.';
                        }
                    }
                }
            } else {
                $resultResponse['message'] = ($_REQUEST['lang'] == 'arabic') ? 'الرجاء ادخال الرقم.' : 'Please enter the number.';
            }
            echo json_encode($resultResponse); exit();
        } catch (\Exception $e) {
            $resultResponse['trace'] = $e->getMessage();
            echo json_encode($resultResponse); exit();
        }
    }

    public function commercialconfirm($vars)
    {
        $resultResponse = ['success'=> false];
        $resultResponse['message'] = ($_REQUEST['lang'] == 'arabic') ? 'حدث خطأ ،، الرجاء المحاولة في وقت لاحق' : 'Something went wrong. Please try again later.';
        try{
            if (empty($_SESSION['cart']['wathqcrName'])) {
                $resultResponse['message'] = ($_REQUEST['lang'] == 'arabic') ? 'حدث خطأ ' :'Something went wrong.';
                $resultResponse['trace'] = 'Session expired.';
                echo json_encode($resultResponse); exit();
            }

            $_SESSION['cart']['wathq'] = 1;
            $_SESSION['cart']['wathqsa'] = 1;
            $resultResponse = ['success' => true, 'redirect' => $this->islicenseverified(), 'redirecturl' => $system_url."cart.php?a=checkout"];
            $command = 'UpdateClient';
            $postData = array(
                'clientid' => $_SESSION['uid'],
                'companyname' => $_SESSION['cart']['wathqcrName'],
            );

            $results = localAPI($command, $postData, $adminUsername);
            echo json_encode($resultResponse); exit();
        } catch (\Exception $e) {
            $resultResponse['trace'] = $e->getMessage();
            echo json_encode($resultResponse); exit();
        }
    }

    public function otpsend($vars)
    {
        $resultResponse = ['success'=> false];
        $resultResponse['message'] = ($_REQUEST['lang'] == 'arabic') ? 'حدث خطأ ،، الرجاء المحاولة في وقت لاحق' : 'Something went wrong. Please try again later.';
        if(!preg_match("/^05[0-9]{8}$/", $_REQUEST['otpnumber'])) {
            $resultResponse['message'] = ($_REQUEST['lang'] == 'arabic') ? 'الصيغه تكون كالتالي, 5X1234567' : 'Format must be like, 5X1234567';
        }
        try{
            if (!empty(array_values($_SESSION['cart']['otp'])[0])) {
                $otp = array_values($_SESSION['cart']['otp'])[0];
            } else {
                $otp = rand(10000, 99999);
            }

            $curl = curl_init();
            if (!empty($_REQUEST['otpnumber'])) {

                $send = "https://mshastra.com/sendurl.aspx?user=20097699&pwd=pxi4di&smstype=13&senderid=Sahara.com&mobileno=".ltrim($_REQUEST['otpnumber'], "0")."&msgtext=your+OTP+is+".$otp."&CountryCode=".$vars['no_prefix'];

                curl_setopt_array($curl, array(
                  CURLOPT_URL => $send,
                  CURLOPT_RETURNTRANSFER => true,
                  CURLOPT_ENCODING => "",
                  CURLOPT_MAXREDIRS => 10,
                  CURLOPT_TIMEOUT => 30,
                  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                  CURLOPT_CUSTOMREQUEST => "GET",
                  CURLOPT_HTTPHEADER => array(
                    "cache-control: no-cache"
                  ),
                ));

                $response = curl_exec($curl);
                $err = curl_error($curl);

                curl_close($curl);
                if ($err) {
                    $resultResponse['trace'] = $err;
                    logModuleCall('kwupload', 'otp', $send, $err, '', '');
                } else {
                    if ($response) {
                        $_SESSION['cart']['otp'] = [$_REQUEST['otpnumber'] => $otp];
                        $resultResponse = ['success' => true];
                    }
                    logModuleCall('kwupload', 'otp', $send, $response, '', '');
                }
            } else {
                $resultResponse['message'] = ($_REQUEST['lang'] == 'arabic') ? 'الرجاء ادخال الرقم.' : 'Please enter the number.';
            }
            echo json_encode($resultResponse); exit();
        } catch (\Exception $e) {
            $resultResponse['trace'] = $e->getMessage();
            echo json_encode($resultResponse); exit();
        }
    }

    public function otpverify($vars)
    {
        $resultResponse = ['success'=> false];
        $resultResponse['message'] = ($_REQUEST['lang'] == 'arabic') ? 'حدث خطأ ،، الرجاء المحاولة في وقت لاحق' : 'Something went wrong. Please try again later.';
        try{
            if (!empty($_REQUEST['otpcode']) && !empty($_REQUEST['otpnumber'])) {
                if ($_SESSION['cart']['otp'][$_REQUEST['otpnumber']] == $_REQUEST['otpcode']) {
                    $_SESSION['cart']['otpverifed'] = 1;
                    $resultResponse = ['success' => true, 'redirect' => $this->islicenseverified(), 'redirecturl' => $system_url."cart.php?a=checkout"];
                } else {
                    $resultResponse['message'] = ($_REQUEST['lang'] == 'arabic') ? 'رمز التحقق غير صحيح' : 'Invalid Otp';
                }
            } else {
                $resultResponse['message'] = ($_REQUEST['lang'] == 'arabic') ? 'الرجاء ادخال رمز التحقق' : 'Please enter the otp.';
            }
            echo json_encode($resultResponse); exit();
        } catch (\Exception $e) {
            $resultResponse['trace'] = $e->getMessage();
            echo json_encode($resultResponse); exit();
        }
    }

    public function otpmailsend($vars)
    {
        $resultResponse = ['success'=> false];
        $resultResponse['message'] = ($_REQUEST['lang'] == 'arabic') ? 'حدث خطأ ،، الرجاء المحاولة في وقت لاحق' : 'Something went wrong. Please try again later.';
        try{
            $otp = rand(10000, 99999);

            $curl = curl_init();
            if (!empty($_REQUEST['emailid'])) {
                if (!filter_var($_REQUEST['emailid'], FILTER_VALIDATE_EMAIL)) {
                    $resultResponse['message'] = ($_REQUEST['lang'] == 'arabic') ? 'يرجى ادخال بريد صحيح' : 'Please enter a valid mail id.';
                    echo json_encode($resultResponse); exit();
                }
                $command = 'GetClientsDetails';
                $postData = array(
                    'clientid' => $_SESSION['uid'],
                    'stats' => false,
                );
                $results = localAPI($command, $postData);
                $email = $results['email'];
                $_SESSION['cart']['mailfallback'] = $email;

                $command = 'UpdateClient';
                $postData = array(
                    'clientid' => $_SESSION['uid'],
                    'email' => $_REQUEST['emailid']
                );
                $results = localAPI($command, $postData, $adminUsername);
                if ($results['result'] != 'success') {
                    $resultResponse['message'] = $results['message'];
                    echo json_encode($resultResponse); exit();
                }
                $command = 'SendEmail';
                $postData = array(
                    'id' => $_SESSION['uid'],
                    'customtype' => 'general',
                    'customsubject' => ($_REQUEST['lang'] == 'arabic') ? 'تحقق البريد' : 'Verify Email',
                    'custommessage' => ($_REQUEST['lang'] == 'arabic') ? 'رمز التحقق هو:'.$otp : 'Your Otp is:'.$otp,
                );

                $results = localAPI($command, $postData, $adminUsername);

                $command = 'UpdateClient';
                $postData = array(
                    'clientid' => $_SESSION['uid'],
                    'email' => $email
                );
                $results = localAPI($command, $postData, $adminUsername);
                $_SESSION['cart']['mailotp'] = [$_REQUEST['emailid'] => $otp];
                $resultResponse = ['success' => true];
            } else {
                $resultResponse['message'] = ($_REQUEST['lang'] == 'arabic') ? 'البرجاء ادخال البريد الإلكتروني' : 'Please enter a mail id.';

            }
            echo json_encode($resultResponse); exit();
        } catch (\Exception $e) {
            $resultResponse['trace'] = $e->getMessage();
            echo json_encode($resultResponse); exit();
        }
    }


    public function otpmailverify($vars)
    {
        $resultResponse = ['success'=> false];
        $resultResponse['message'] = ($_REQUEST['lang'] == 'arabic') ? 'حدث خطأ ،، الرجاء المحاولة في وقت لاحق' : 'Something went wrong. Please try again later.';
        try{
            if (!empty($_REQUEST['otpcode']) && !empty($_REQUEST['emailid'])) {
                if (!filter_var($_REQUEST['emailid'], FILTER_VALIDATE_EMAIL)) {
                    $resultResponse['message'] = ($_REQUEST['lang'] == 'arabic') ? 'الرجاء ادخال بريد صحيح' : 'Please enter a valid mail id.' ;
                    echo json_encode($resultResponse); exit();
                }
                if ($_SESSION['cart']['mailotp'][$_REQUEST['emailid']] == $_REQUEST['otpcode']) {
                    $_SESSION['cart']['mailotpverifed'] = 1;
                    $resultResponse = ['success' => true, 'redirect' => $this->islicenseverified(), 'redirecturl' => $system_url."cart.php?a=checkout"];
                } else {
                    $resultResponse['message'] = ($_REQUEST['lang'] == 'arabic') ? 'رمز التحقق غير صحيح' : 'Invalid Otp';
                }
            } else {
                $resultResponse['message'] = ($_REQUEST['lang'] == 'arabic') ? 'الرجاء ادخال رمز التحقق' : 'Please enter the otp.';
            }
            echo json_encode($resultResponse); exit();
        } catch (\Exception $e) {
            $resultResponse['trace'] = $e->getMessage();
            echo json_encode($resultResponse); exit();
        }
    }
    
    public function kwcheckoutform()
    {
        $return = array();

        if (!(isset($_SESSION['uid']) && intval($_SESSION['uid'])!==0)) {
            $return['validation'] = false;
        }
        $modulelink = $vars['modulelink'];
        if (isset($_SESSION['cart']['domains']) && !empty($_SESSION['cart']['domains'])) {
            $uploadDomain = [];
            $isRegisterType = false;
            foreach($_SESSION['cart']['domains'] as $key => $value) {
                if ($value['type'] != 'register') continue;
                $isRegisterType = true;
                if (!isset($value['kwverified']) || $value['kwverified'] != 1) {
                    $uploadDomain[$key] = $value['domain'];
                }
            }
            if (count($uploadDomain)) {
                $return['validation'] = true;
            } else {
                $return['validation'] = false;
            }
        } else {
            $return['validation'] = false;
        }
        echo json_encode($return); exit();
    }

    protected function islicenseverified($iscomsa = false, $issa = false) {
        if ((isset($_SESSION['cart']['otpverifed']) && $_SESSION['cart']['otpverifed'] == 1) && (isset($_SESSION['cart']['mailotpverifed']) && $_SESSION['cart']['mailotpverifed'] == 1)) {
            if ($issa) {
                if (isset($_SESSION['cart']['wathqsa']) && $_SESSION['cart']['wathqsa'] == 1) {
                    return true;
                }
            } elseif (isset($_SESSION['cart']['wathq']) && $_SESSION['cart']['wathq'] == 1) {
                return true;
            }
        }
        return false;
    }

    public function issaverified($vars)
    {
        $resultResponse = ['success'=> false];
        if (!empty($_REQUEST['nonwathqcommercial']) && $_REQUEST['nonwathqcommercial'] != "false" && (isset($_SESSION['cart']['otpverifed']) && $_SESSION['cart']['otpverifed'] == 1) && (isset($_SESSION['cart']['mailotpverifed']) && $_SESSION['cart']['mailotpverifed'] == 1)) {
            $resultResponse['success'] = true;
            echo json_encode($resultResponse); exit();
        }
        $iscomsa = false;
        $issa = false;
        $domainSuffix = substr($_REQUEST['domainname'], strpos($_REQUEST['domainname'], "."));
        if ($domainSuffix == '.com.sa') {
            $iscomsa = true;
        } elseif ($domainSuffix == '.sa' || $domainSuffix == '.السعودية') {
            $issa = true;
        }
        if (isset($_SESSION['cart']['domains']) && !empty($_SESSION['cart']['domains'])) {
            if ($this->islicenseverified($iscomsa, $issa)) {
                $resultResponse['success'] = true;
            }
        }
        echo json_encode($resultResponse); exit();
    }
}
