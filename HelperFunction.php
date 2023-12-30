<?php

namespace WHMCS\Module\Addon\coccaepp_logs;
use WHMCS\Database\Capsule;


class HelperFunction
{
   
    public function update_registrar_logs_setting($setting,$value)
    {
        $col = Capsule::table("coccaepp_logs_setting")->where("setting",$setting)->count();
    
        if(!empty($col))
        {
            Capsule::table("coccaepp_logs_setting")
                    ->where("setting",$setting)
                    ->update(array('value'=>$value));
        }
        else
        {
            Capsule::table("coccaepp_logs_setting")
                    ->insert(array(
                                    'setting'=>$setting,
                                    'value'=>$value
                                  ));
        }
    }
    public function getLogSetting($setting = '') 
    {
        if(!empty($setting))
        {
            return Capsule::table("coccaepp_logs_setting")->where("setting",$setting)->value('value');
        }
        else
        {
            return Capsule::table("coccaepp_logs_setting")->get();
        }
        
    }
    static function coccaepp_logModuleCall($module, $action, $request,$response)
    {
        
        if(empty(self::getLogSetting('registrar_logs')))
        {
            return;
        }
        
        if (is_array($request)) 
        {
            $request = print_r($request, true);
        }


        if (is_array($response)) 
        {
            $response = print_r($response, true);
        }
        
        Capsule::table("coccaepp_registrar_logs")
                    ->insert(array(
                                    'datetime'=>\WHMCS\Carbon::now(),
                                    'module'=>$module,
                                    'action'=>$action,
                                    'request'=>$request,
                                    'response'=>$response
                                  ));
                                 

    }
    static function coccaepp_approval_logModuleCall($domain, $action, $request,$response)
    {
        
        if(empty(self::getLogSetting('approval_logs')))
        {
            return;
        }
        
        if (is_array($request)) 
        {
            $request = print_r($request, true);
        }


        if (is_array($response)) 
        {
            $response = print_r($response, true);
        }
        
        Capsule::table("coccaepp_approval_logs")
                    ->insert(array(
                                    'datetime'=>\WHMCS\Carbon::now(),
                                    'domain'=>$domain,
                                    'action'=>$action,
                                    'request'=>$request,
                                    'response'=>$response
                                  ));
                                 

    }
    static function coccaepp_CheckoutApproval_logModuleCall($userId, $domain, $ip_address,$acceptance)
    {
        
        if(empty(self::getLogSetting('checkout_approval_logs')))
        {
            return;
        }
        /* 
            if (is_array($request)) 
            {
                $request = print_r($request, true);
            }


            if (is_array($response)) 
            {
                $response = print_r($response, true);
            }
        */
        $clientData = Capsule::table('tblclients')->select('firstname', 'lastname')->where('id', $userId)->first();
        $fullname =  $clientData->firstname.' '. $clientData->lastname;
        Capsule::table("coccaepp_checkout_approval_logs")
                    ->insert(array(
                                    'datetime'=>\WHMCS\Carbon::now(),
                                    'username'=>$fullname,
                                    'domain'=>$domain,
                                    'ip_address'=>$ip_address,
                                    'acceptance'=>$acceptance
                                  ));
                                 

    }
    function getFullName($clientID)
    {
        $clientData = Capsule::table('tblclients')->select('firstname', 'lastname')->where('id', $clientID)->first();
        return $clientData->firstname.' '. $clientData->lastname;
    }
    
    public function deleteSetting($setting) 
    {
        return Capsule::table("wa_settings")->where("setting",$setting)->delete();
    }
    
    public function getModLogs($tabelName,$page,$limit = 50,$orderbyCol = 'id',$orderby = 'desc')
    {
        $sql =  Capsule::table($tabelName);
        $sql->skip(($page)*$limit);
        $sql->take($limit);
        $sql->orderBy($orderbyCol,$orderby);
        
        return $sql->get();
    }

    public function getModLogsCount($tabelName)
    {
        return Capsule::table($tabelName)->count();
    }
    
    
    function deleteLogs($tabelName)
    {
        return Capsule::table($tabelName)->delete();
    }
    function deleteCheckoutApprovalLogs()
    {
        return Capsule::table('coccaepp_checkout_approval_logs')->delete();
    }
    
    /* public function getSetting($setting = '') 
    {
        if(!empty($setting))
        {
            return Capsule::table("wa_settings")->where("setting",$setting)->value('value');
        }
        else
        {
            return Capsule::table("wa_settings")->get();
        }
        
    } */

     /* public function updateSetting($setting,$value)
    {
        $col = Capsule::table("wa_settings")->where("setting",$setting)->count();
    
        if(!empty($col))
        {
            Capsule::table("wa_settings")
                    ->where("setting",$setting)
                    ->update(array('value'=>$value));
        }
        else
        {
            Capsule::table("wa_settings")
                    ->insert(array(
                                    'setting'=>$setting,
                                    'value'=>$value
                                  ));
        }
    } */
    
    /* public function getLocalStorage($id = '') 
    {
        
        if(!empty($id))
        {
            return Capsule::table("tblstorageconfigurations")->where('id',$id)->first();
        }
        else
        {
            return Capsule::table("tblstorageconfigurations")->get();
        }
        
    } */
    
    
  /*   public function generateInvoice($invoiceid,$directoryName)
    {
        
        #create folder if not exist
        if(!is_dir($directoryName))
        {
            mkdir($directoryName);
        }

        if(is_dir($directoryName))
        {

            if(!empty($invoiceid))
            {
                global $whmcs;
                global $CONFIG;
                global $_LANG;
                global $currency;

                $fileName = $directoryName.'/Invoice-'.$invoiceid;

                $invoice = new Invoice($invoiceid,$fileName);
                $invoice->pdfCreate();
                $invoice->pdfInvoicePage($invoiceid);
                $pdfdata = $invoice->pdfOutput();
                return $pdfdata;

            }

        }

    } */
    
   /*  public function updateTemplate($name,$value,$type)
    {
        $col = Capsule::table("wa_templates")->where("name",$name)->where("type",$type)->count();
    
        if(!empty($col))
        {
            Capsule::table("wa_templates")
                    ->where("name",$name)
                    ->where("type",$type)
                    ->update(array('value'=>$value));
        }
        else
        {
            Capsule::table("wa_templates")
                    ->insert(array(
                                    'name'=>$name,
                                    'type'=>$type,
                                    'value'=>$value
                                  ));
        }
    } */
    
    /* public function getTemplates($type,$name = '') 
    {
        if(!empty($name))
        {
            return Capsule::table("wa_templates")->where("type",$type)->where("name",$name)->value('value');
        }
        else
        {
            return Capsule::table("wa_templates")->where("type",$type)->get();
        }
        
    } */
    
    /* public function checkCustomFieldExist($customFieldName,$pid,$type = 'product')
    {
        return Capsule::table('tblcustomfields')->where('type',$type)->where('relid', $pid)->where('fieldname', $customFieldName)->value('id');
    } */


    /* public function createCustomField($fieldname,$relid,$fieldtype,$type = 'product', $description = '', $fieldoptions = '', $regexpr = '', $adminonly = '', $required = '', $showorder = '', $showinvoice = '', $sortorder = '')
    {
        return Capsule::table('tblcustomfields')->insertGetId(
                                                            array(

                                                                'type' => $type,

                                                                'relid' => $relid,

                                                                'fieldname' => $fieldname,

                                                                'fieldtype' => $fieldtype,

                                                                'description' => $description,

                                                                'fieldoptions' => $fieldoptions,

                                                                'regexpr' => $regexpr,

                                                                'adminonly' => $adminonly,

                                                                'required' => $required,

                                                                'showorder' => $showorder,

                                                                'showinvoice' => $showinvoice,

                                                                'sortorder' => $sortorder,

                                                    ));

    } */
    
    /* public function getClientDetails($userid)
    {
        return Capsule::table('tblclients')
                ->where('id',$userid)
                ->select('id','firstname','lastname','email','status','defaultgateway','companyname','address1','address2','city','state','postcode','country','phonenumber')
                ->first();
    } */
    
    
    /* function insertModLog($action,$phone,$request,$response)
    {
        if(empty(self::getSetting('modLog')))
        {
            return;
        }
        
        if (is_array($request)) 
        {
            $request = print_r($request, true);
        }


        if (is_array($response)) 
            {
                $response = print_r($response, true);
        }
        
        Capsule::table("wa_modLogs")
                    ->insert(array(
                                    'datetime'=>\WHMCS\Carbon::now(),
                                    'action'=>$action,
                                    'phone'=>$phone,
                                    'request'=>$request,
                                    'response'=>$response
                                  ));
    } */
    
    
    /* public function getModLogs($filters = array(),$orderbyCol = 'id',$orderby = 'desc')
    {
        $sql =  Capsule::table('wa_modLogs');
        
        if ($filters["phone"]) 
        {
            $sql->where('phone','like',"%".trim($filters["phone"])."%");
            
        }

        $sql->orderBy($orderbyCol,$orderby);
        
        return $sql->get();
    } */
    
    
    /* function deleteModLogs()
    {
        return Capsule::table('wa_modLogs')->delete();
    } */
    
    
    /* public function formatPhoneNumber($phoneNumber)
    {
        $phoneNumber = str_replace('.', '', $phoneNumber);
        $phoneNumber = str_replace('+', '', $phoneNumber);
        $phoneNumber = str_replace(' ', '', $phoneNumber);
        $phoneNumber = str_replace('-', '', $phoneNumber);
        $phoneNumber = str_replace('(', '', $phoneNumber);
        $phoneNumber = str_replace(')', '', $phoneNumber);
        
        return $phoneNumber;
    }
     */
    /* public function formatMessage($msg,$userid,$type = '',$relid = '')
    {
        global $CONFIG;
        global $_LANG;

        $mergeFields = array();
        $mergeFields['time'] = getTodaysDate(TRUE)." ".date("H:i:s");
        $mergeFields['date'] = getTodaysDate(TRUE);

        if(!empty($this->getSetting('sign')))
        {
            $mergeFields['signature'] = $this->getSetting('sign');
        }
        
        $mergeFields['company_name'] = $CONFIG['CompanyName'];
        $mergeFields['company_domain'] = $CONFIG['Domain'];
        $mergeFields['whmcs_url'] = $CONFIG['SystemURL'];

        if($type == 'invoice' && !empty($relid))
        {
            $invoice = new \WHMCS\Invoice($relid);
            $data = $invoice->getOutput();
            $invoiceitems = $invoice->getLineItems();
            $invoicedescription = "";
            
            foreach ($invoiceitems as $item) 
            {
                $invoicedescription .= $item['description'] . " " . $item['amount'] . "\n";
            }

            $invoicedescription .= "------------------------------------------------------\n";
            $invoicedescription .= $_LANG['invoicessubtotal'] . ": " . $data['subtotal'] . "\n";

            if (0 < $data['taxrate']) 
            {
                $invoicedescription .= $data['taxrate'] . "% " . $data['taxname'] . ": " . $data['tax'] . "\n";
            }


            if (0 < $data['taxrate2']) 
            {
                $invoicedescription .= $data['taxrate2'] . "% " . $data['taxname2'] . ": " . $data['tax2'] . "\n";
            }

            $invoicedescription .= $_LANG['invoicescredit'] . ": " . $data['credit'] . "\n";
            $invoicedescription .= $_LANG['invoicestotal'] . ": " . $data['total'] . "";
            $paymentbutton = $invoice->getPaymentLink();
            
            
            
            $mergeFields['invoice_id'] = $data['invoiceid'];
            $mergeFields['invoice_num'] = $data['invoicenum'];
            $mergeFields['invoice_date_created'] = $data['date'];
            $mergeFields['invoice_date_due'] = $data['duedate'];
            $mergeFields['invoice_date_paid'] = $data['datepaid'];
            $mergeFields['invoice_items'] = $invoiceitems;
            $mergeFields['invoice_html_contents'] = $invoicedescription;
            $mergeFields['invoice_subtotal'] = $data['subtotal'];
            $mergeFields['invoice_credit'] = $data['credit'];
            $mergeFields['invoice_tax'] = $data['tax'];
            $mergeFields['invoice_tax_rate'] = $data['taxrate'] . "%";
            $mergeFields['invoice_tax2'] = $data['tax2'];
            $mergeFields['invoice_tax_rate2'] = $data['taxrate2'] . "%";
            $mergeFields['invoice_total'] = $data['total'];
            $mergeFields['invoice_amount_paid'] = $data['amountpaid'];
            $mergeFields['invoice_balance'] = $data['balance'];
            $mergeFields['invoice_status'] = $data['statuslocale'];
            $mergeFields['invoice_last_payment_amount'] = $data['lastpaymentamount'];
            $mergeFields['invoice_last_payment_transid'] = $data['lastpaymenttransid'];
            $mergeFields['invoice_payment_link'] = $paymentbutton;
            $mergeFields['invoice_payment_method'] = $data['paymentmethod'];
            $mergeFields['invoice_link'] = $CONFIG['SystemURL'] . "/viewinvoice.php?id=" . $data['id'];
            $mergeFields['invoice_notes'] = $data['notes'];
            $mergeFields['invoice_subscription_id'] = $data['subscrid'];
            $mergeFields['invoice_previous_balance'] = $data['clientpreviousbalance'];
            $mergeFields['invoice_all_due_total'] = $data['clienttotaldue'];
            $mergeFields['invoice_total_balance_due'] = $data['clientbalancedue'];
        }
        
        if($type == 'service' && !empty($relid))
        {
            $gatewaysarray = array();
            
            $result = select_query("tblpaymentgateways", "gateway,value", array("setting" => "name"), "order", "ASC");

            while ($data = mysql_fetch_array($result)) 
            {
                    $gatewaysarray[$data['gateway']] = $data['value'];
            }

            $result = select_query("tblhosting", "tblhosting.*,tblproducts.name,tblproducts.description", array("tblhosting.id" => $relid), "", "", "", "tblproducts ON tblproducts.id=tblhosting.packageid");
						
            $data = mysql_fetch_array($result);
            
            $id = $data['id'];
            $userid = $data['userid'];
            $orderid = $data['orderid'];
            $regdate = $data['regdate'];
            $nextduedate = $data['nextduedate'];
            $orderno = $data['orderno'];
            $domain = $data['domain'];
            $server = $data['server'];
            $package = $data['name'];
            $productdescription = $data['description'];
            $packageid = $data['packageid'];
            $upgrades = $data['upgrades'];
            $paymentmethod = $data['paymentmethod'];
            $paymentmethod = $gatewaysarray[$paymentmethod];

            if ($regdate == $nextduedate) 
            {
                $amount = $data['firstpaymentamount'];
            }
            else
            {
                $amount = $data['amount'];
            }

            $firstpaymentamount = $data['firstpaymentamount'];
            $recurringamount = $data['amount'];
            $billingcycle = $data['billingcycle'];
            $domainstatus = $data['domainstatus'];
            $username = $data['username'];
            $password = decrypt($data['password']);
            $dedicatedip = $data['dedicatedip'];
            $assignedips = nl2br($data['assignedips']);
            $dedi_ns1 = $data['ns1'];
            $dedi_ns2 = $data['ns2'];
            $subscriptionid = $data['subscriptionid'];
            $suspendreason = $data['suspendreason'];
            $canceltype = get_query_val("tblcancelrequests", "type", array("relid" => $data['id']), "id", "DESC");
            $regdate = fromMySQLDate($regdate, 0, 1);

            if ($nextduedate != "-") 
            {
                    $nextduedate = fromMySQLDate($nextduedate, 0, 1);
            }

            getUsersLang($userid);
            
            $currency = getCurrency($userid);

            if ($domainstatus == "Suspended" && !$suspendreason) 
            {
                    $suspendreason = $_LANG['suspendreasonoverdue'];
            }

            $domainstatus = $_LANG["clientarea" . strtolower(str_replace(" ", "", $domainstatus))];
            $canceltype = $_LANG["clientareacancellation" . strtolower(str_replace(" ", "", $canceltype))];

            if ($server) 
            {
                $result3 = select_query("tblservers", "", array("id" => $server));
                $data3 = mysql_fetch_array($result3);
                $servername = $data3['name'];
                $serverip = $data3['ipaddress'];
                $serverhostname = $data3['hostname'];
                $ns1 = $data3['nameserver1'];
                $ns1ip = $data3['nameserver1ip'];
                $ns2 = $data3['nameserver2'];
                $ns2ip = $data3['nameserver2ip'];
                $ns3 = $data3['nameserver3'];
                $ns3ip = $data3['nameserver3ip'];
                $ns4 = $data3['nameserver4'];
                $ns4ip = $data3['nameserver4ip'];
            }

            $billingcycleforconfigoptions = strtolower($billingcycle);
            $billingcycleforconfigoptions = preg_replace("/[^a-z]/i", "", $billingcycleforconfigoptions);
            $langbillingcycle = $billingcycleforconfigoptions;
            $billingcycleforconfigoptions = str_replace("lly", "l", $billingcycleforconfigoptions);

            if ($billingcycleforconfigoptions == "free account") 
            {
                $billingcycleforconfigoptions = "monthly";
            }

            $configoptions = array();
            $configoptionshtml = "";
            $query4 = "SELECT tblproductconfigoptions.id, tblproductconfigoptions.optionname AS confoption, tblproductconfigoptions.optiontype AS conftype, tblproductconfigoptionssub.optionname, tblhostingconfigoptions.qty FROM tblhostingconfigoptions INNER JOIN tblproductconfigoptions ON tblproductconfigoptions.id = tblhostingconfigoptions.configid INNER JOIN tblproductconfigoptionssub ON tblproductconfigoptionssub.id = tblhostingconfigoptions.optionid INNER JOIN tblhosting ON tblhosting.id=tblhostingconfigoptions.relid INNER JOIN tblproductconfiglinks ON tblproductconfiglinks.gid=tblproductconfigoptions.gid WHERE tblhostingconfigoptions.relid='" . (int)$id . "' AND tblproductconfiglinks.pid=tblhosting.packageid ORDER BY tblproductconfigoptions.`order`,tblproductconfigoptions.id ASC";
            $result4 = full_query($query4);

            if ($data4 = mysql_fetch_array($result4)) 
            {
                $confoption = $data4['confoption'];
                $conftype = $data4['conftype'];

                if (strpos($confoption, "|")) 
                {
                    $confoption = explode("|", $confoption);
                    $confoption = trim($confoption[1]);
                }

                $optionname = $data4['optionname'];
                $optionqty = $data4['qty'];

                if (strpos($optionname, "|")) 
                {
                    $optionname = explode("|", $optionname);
                    $optionname = trim($optionname[1]);
                }


                if ($conftype == 3) 
                {
                    if ($optionqty) 
                    {
                        $optionname = $_LANG['yes'];
                    }
                    else
                    {
                        $optionname = $_LANG['no'];
                    }
                }
                else
                {
                    if ($conftype == 4) 
                    {
                        $optionname = "" . $optionqty . " x " . $optionname;
                    }
                }

                $configoptions[] = array("id" => $data4['id'], "option" => $confoption, "type" => $conftype, "value" => $optionname, "qty" => $optionqty, "setup" => $CONFIG['CurrencySymbol'] . $data4['setup'], "recurring" => $CONFIG['CurrencySymbol'] . $data4['recurring']);
                $configoptionshtml .= "" . $confoption . ": " . $optionname . " " . $CONFIG['CurrencySymbol'] . $data4['recurring'] . "<br>\r\n";
            }

            $mergeFields['service_order_id'] = $orderid;
            $mergeFields['service_id'] = $id;
            $mergeFields['service_reg_date'] = $regdate;
            $mergeFields['service_product_name'] = $package;
            $mergeFields['service_product_description'] = $productdescription;
            $mergeFields['service_config_options'] = $configoptions;
            $mergeFields['service_config_options_html'] = $configoptionshtml;
            $mergeFields['service_domain'] = $domain;
            $mergeFields['service_server_name'] = $servername;
            $mergeFields['service_server_hostname'] = $serverhostname;
            $mergeFields['service_server_ip'] = $serverip;
            $mergeFields['service_dedicated_ip'] = $dedicatedip;
            $mergeFields['service_assigned_ips'] = $assignedips;

            if ($dedi_ns1 != "") 
            {
                $mergeFields['service_ns1'] = $dedi_ns1;
                $mergeFields['service_ns2'] = $dedi_ns2;
            }
            else
            {
                $mergeFields['service_ns1'] = $ns1;
                $mergeFields['service_ns2'] = $ns2;
                $mergeFields['service_ns3'] = $ns3;
                $mergeFields['service_ns4'] = $ns4;
            }

            $mergeFields['service_ns1_ip'] = $ns1ip;
            $mergeFields['service_ns2_ip'] = $ns2ip;
            $mergeFields['service_ns3_ip'] = $ns3ip;
            $mergeFields['service_ns4_ip'] = $ns4ip;
            $mergeFields['service_payment_method'] = $paymentmethod;
            $mergeFields['service_first_payment_amount'] = formatCurrency($firstpaymentamount);
            $mergeFields['service_recurring_amount'] = formatCurrency($recurringamount);
            $mergeFields['service_billing_cycle'] = $_LANG["orderpaymentterm" . $langbillingcycle];
            $mergeFields['service_next_due_date'] = $nextduedate;
            $mergeFields['service_status'] = $domainstatus;
            $mergeFields['service_username'] = $username;
            $mergeFields['service_password'] = $password;
            $mergeFields['service_subscription_id'] = $subscriptionid;
            $mergeFields['service_suspension_reason'] = $suspendreason;
            $mergeFields['service_cancellation_type'] = $canceltype;

            if (!function_exists("getCustomFields")) 
            {
                require dirname(__FILE__) . "/customfieldfunctions.php";
            }

            $customfields = getCustomFields("product", $packageid, $relid, true, "");
            $mergeFields['service_custom_fields'] = array();
            
            foreach ($customfields as $customfield) 
            {
                $customfieldname = preg_replace("/[^0-9a-z]/", "", strtolower($customfield['name']));
                $mergeFields["service_custom_field_" . $customfieldname] = $customfield['value'];
                $mergeFields['service_custom_fields'][] = $customfield['value'];
            }
        }
        
        
        
        if($type == 'domain' && !empty($relid))
        {
            $result = select_query("tbldomains", "", array("id" => $relid));
            $data = mysql_fetch_array($result);
            
            $id = $data["id"];
            if (!$id) 
            {
                throw new \WHMCS\Exception("Invalid domain id provided");
            }
            
            $userid = $data["userid"];
            $orderid = $data["orderid"];
            $registrationdate = $data["registrationdate"];
            $status = $data["status"];
            $domain = $data["domain"];
            $firstpaymentamount = $data["firstpaymentamount"];
            $recurringamount = $data["recurringamount"];
            $registrar = $data["registrar"];
            $registrationperiod = $data["registrationperiod"];
            $expirydate = $data["expirydate"];
            $nextduedate = $data["nextduedate"];
            $gateway = $data["paymentmethod"];
            $dnsmanagement = $data["dnsmanagement"];
            $emailforwarding = $data["emailforwarding"];
            $idprotection = $data["idprotection"];
            $donotrenew = $data["donotrenew"];
            
            $status = \Lang::trans("clientarea" . strtolower(str_replace(" ", "", $status)));
            if ($expirydate == "0000-00-00" || empty($expirydate)) {
                $expirydate = $nextduedate;
            }
            $expirydays_todaysdate = date("Ymd");
            $expirydays_todaysdate = strtotime($expirydays_todaysdate);
            $expirydays_expirydate = strtotime($expirydate);
            $expirydays = round(($expirydays_expirydate - $expirydays_todaysdate) / 86400);
            $expirydays_nextduedate = strtotime($nextduedate);
            $nextduedays = round(($expirydays_nextduedate - $expirydays_todaysdate) / 86400);
            $registrationdate = fromMySQLDate($registrationdate, 0, 1);
            $expirydate = fromMySQLDate($expirydate, 0, 1);
            $nextduedate = fromMySQLDate($nextduedate, 0, 1);
            $domainparts = explode(".", $domain, 2);
            
            
            $mergeFields["domain_id"] = $id;
            $mergeFields["domain_order_id"] = $orderid;
            $mergeFields["domain_reg_date"] = $registrationdate;
            $mergeFields["domain_status"] = $status;
            $mergeFields["domain_name"] = $domain;
            list($mergeFields["domain_sld"], $mergeFields["domain_tld"]) = $domainparts;
            $mergeFields["domain_first_payment_amount"] = formatCurrency($firstpaymentamount);
            $mergeFields["domain_recurring_amount"] = formatCurrency($recurringamount);
            $mergeFields["domain_registrar"] = $registrar;
            $mergeFields["domain_reg_period"] = $registrationperiod . " " . \Lang::trans("orderyears");
            $mergeFields["domain_expiry_date"] = $expirydate;
            $mergeFields["domain_next_due_date"] = $nextduedate;
            $mergeFields["domain_renewal_url"] = fqdnRoutePath("domain-renewal", $domain);
            $mergeFields["domains_manage_url"] = \App::getSystemUrl() . "clientarea.php?action=domains";
            
            if (0 <= $expirydays) 
            {
                $mergeFields["days_until_expiry"] = $expirydays;
                $mergeFields["domain_days_until_expiry"] = $expirydays;
                $mergeFields["domain_days_after_expiry"] = 0;
            }
            else
            {
                $mergeFields["days_until_expiry"] = 0;
                $mergeFields["domain_days_until_expiry"] = 0;
                $mergeFields["domain_days_after_expiry"] = $expirydays * -1;
            }
            
            if (0 <= $nextduedays) 
            {
                $mergeFields["domain_days_until_nextdue"] = $nextduedays;
                $mergeFields["domain_days_after_nextdue"] = 0;
            }
            else
            {
                $mergeFields["domain_days_until_nextdue"] = 0;
                $mergeFields["domain_days_after_nextdue"] = $nextduedays * -1;
            }
            
            $mergeFields["domain_dns_management"] = $dnsmanagement ? "1" : "0";
            $mergeFields["domain_email_forwarding"] = $emailforwarding ? "1" : "0";
            $mergeFields["domain_id_protection"] = $idprotection ? "1" : "0";
            $mergeFields["domain_do_not_renew"] = $donotrenew ? "1" : "0";
            $mergeFields["expiring_domains"] = array();
            $mergeFields["domains"] = array();
        }
        
        if($type == 'ticket' && !empty($relid))
        {
            $result = select_query("tbltickets", "", array("id" => $relid));
            $data = mysql_fetch_array($result);
            $id = $data["id"];
            
            if (!$id) 
            {
                throw new \WHMCS\Exception("Invalid ticket id provided");
            }
            
            $deptid = $data["did"];
            $tid = $data["tid"];
            $ticketcc = $data["cc"];
            $c = $data["c"];
            $userid = $data["userid"];
            $contactid = $data["contactid"];
            $name = $data["name"];
            $email = $data["email"];
            $date = $data["date"];
            $title = $data["title"];
            $tmessage = $data["message"];
            $status = $data["status"];
            $urgency = $data["urgency"];
            $attachment = $data["attachment"];
            $editor = $data["editor"];
            
            if ($ticketcc) 
            {
                $ticketcc = explode(",", $ticketcc);
                foreach ($ticketcc as $ccaddress) {
                    $this->message->addRecipient("cc", $ccaddress);
                }
            }
            
            
            
            $urgency = \Lang::trans("supportticketsticketurgency" . strtolower($urgency));
            if (!function_exists("getStatusColour")) {
                require_once ROOTDIR . "/includes/ticketfunctions.php";
            }
            $status = getStatusColour($status);
            $result = select_query("tblticketdepartments", "", array("id" => $deptid));
            $data = mysql_fetch_array($result);
            
            $departmentname = $data["name"];
            $contentType = "ticket_msg";
            $replyid = 0;
            
            
            $markup = new \WHMCS\View\Markup\Markup();
            $markupFormat = $markup->determineMarkupEditor($contentType, $editor);
            
            $tmessage = $markup->transform($tmessage, $markupFormat, true);
            $kbarticles = getKBAutoSuggestions($tmessage);
            $kb_auto_suggestions = "";
            
            $sysurl = \App::getSystemURL();
            
            foreach ($kbarticles as $kbarticle) 
            {
                $kb_auto_suggestions .= "<a href=\"" . $sysurl . "knowledgebase.php?action=displayarticle&id=" . $kbarticle["id"] . "\" target=\"_blank\">" . $kbarticle["title"] . "</a> - " . $kbarticle["article"] . "...<br />\n";
            }
            
           
            $mergeFields["ticket_id"] = $tid;
            $mergeFields["ticket_reply_id"] = $replyid;
            $mergeFields["ticket_department"] = $departmentname;
            $mergeFields["ticket_date_opened"] = $date;
            $mergeFields["ticket_subject"] = $title;
            $mergeFields["ticket_message"] = $tmessage;
            $mergeFields["ticket_status"] = $status;
            $mergeFields["ticket_priority"] = $urgency;
            $mergeFields["ticket_url"] = $CONFIG['SystemURL'] . "viewticket.php?tid=" . $tid . "&c=" . $c;
            $mergeFields["ticket_link"] = "<a href=\"" . $CONFIG['SystemURL'] . "viewticket.php?tid=" . $tid . "&c=" . $c . "\">" . $sysurl . "viewticket.php?tid=" . $tid . "&c=" . $c . "</a>";
            $mergeFields["ticket_auto_close_time"] = \WHMCS\Config\Setting::getValue("CloseInactiveTickets");
            $mergeFields["ticket_kb_auto_suggestions"] = $kb_auto_suggestions;
            
        }
        
        #Common merge fields
        if(!empty($userid))
        {
            $clientDetails = $this->getClientDetails($userid);

            if($clientDetails)
            {
                $mergeFields['client_id'] = $clientDetails->id;
                $mergeFields['client_name'] = $clientDetails->firstname." ".$clientDetails->lastname;
                $mergeFields['client_first_name'] = $clientDetails->firstname;
                $mergeFields['client_last_name'] = $clientDetails->lastname;
                $mergeFields['client_company_name'] = $clientDetails->companyname;
                $mergeFields['client_email'] = $clientDetails->email;
                $mergeFields['client_address1'] = $clientDetails->address1;
                $mergeFields['client_address2'] = $clientDetails->address2;

                $mergeFields['client_city'] = $clientDetails->city;
                $mergeFields['client_state'] = $clientDetails->state;
                $mergeFields['client_postcode'] = $clientDetails->postcode;
                $mergeFields['client_country'] = $clientDetails->country;
                $mergeFields['client_phonenumber'] = $clientDetails->phonenumber;

            }
        }
        

        #Getting message
        if($msg)
        {
            foreach ($mergeFields as $key => $value) 
            {
                if (strpos($msg,'{$'.$key.'}') !== false) 
                {
                    $msg = str_ireplace('{$'.$key.'}',$value,$msg);
                }
            }
            
            $msg = str_replace("<br>", "\n", $msg);
            $msg = str_replace("<br />", "\n", $msg);
            
            $msg = str_replace("<b>", "*", $msg);
            $msg = str_replace("</b>", "*", $msg);
            
            $msg = str_replace("<i>", "_", $msg);
            $msg = str_replace("</i>", "_", $msg);
            
            $msg = str_replace("<strike>", "~", $msg);
            $msg = str_replace("</strike>", "~", $msg);
            
            
            $msg = strip_tags($msg);
            
            return $msg;
        }
        
    } */
    
    /* function generateAttachment($fileName,$path)
    {
        $storage = \Storage::ticketAttachments();
        $fileString = $storage->read($fileName);

        $ifp = fopen($path.'/'.substr($fileName, 7),'wb'); 
        fwrite($ifp,$fileString);
        fclose( $ifp ); 

        return true; 
    } */
    
    
    /* public function uploadFile($path)
    {
        $target_dir = $path."/";
        $target_file = $target_dir . basename($_FILES["uploadfilewa"]["name"]);
        
        return move_uploaded_file($_FILES["uploadfilewa"]["tmp_name"], $target_file);
    } */
    
}
