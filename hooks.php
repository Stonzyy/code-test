<?php

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\DomainContract\Helper;


require_once(ROOTDIR . '/vendor/tecnickcom/tcpdf/tcpdf.php'); // Include TCPDF

class MYPDF extends TCPDF {

  
    public function Header() {
        
        $image_file1 = Capsule::table('tbladdonmodules')->where(['module'=> "domaincontract" , 'setting'=>"Header Image 1"])->value('value');
        $image_file2 = Capsule::table('tbladdonmodules')->where(['module'=> "domaincontract" , 'setting'=>"Header Image 2"])->value('value');
        
       
        $this->Image($image_file1, 10, 10, 40, '', 'PNG', '', 'T', false, 300, '', false, false, 0, false, false, false);
        $this->SetLineWidth(1);
        $this->Image($image_file2, 180, 10, 25, '', 'PNG', '', 'R', false, 300, '', false, false, 0, false, false, false);
       
        
        

    }

    // Page footer
    public function Footer() {

        $this->SetY(-20);
        $fullWidthImage = Capsule::table('tbladdonmodules')->where(['module'=> "domaincontract" , 'setting'=>"Footer Image"])->value('value');
        
        $this->Image($fullWidthImage, 5, $this->GetY(), $this->getPageWidth() - 10, 20, '', '', '', false, 300, '', false, false, 0, false, false, false);
        $this->writeHTML('<hr>', true, false, true, 5, '');
        
      
    }
}



add_hook('AcceptOrder', 1, function($vars) {
    $orderId = $vars['orderid'];
    $domainDetails = Capsule::table("tbldomains")->where("orderid", $orderId)->first();
    if ((array)$domainDetails > 0) {
        $domain    = $domainname = $domainDetails->domain;
        $client_id = $userid     = $domainDetails->userid;
        $domainid  = $domainDetails->id;
        global $downloads_dir;
        global $attachments_dir;
    
        $response = [];
        try {
            global $CONFIG;
    
            $domain_data = explode('.', $domain);        
            $tld = end($domain_data);
            
            if($tld == "sa"){
    
                $helper = new Helper;
                $pdf = new MYPDF('P', 'mm', 'A4', true, 'UTF-8', false);
                $pdf->SetMargins(20, 35, 20);
                $pdf->Ln(20);
                $pdf->repeat_header = true;
                $pdf->Line($pdf->GetX(), $pdf->GetY()+10, $pdf->GetX()+$pdf->getPageWidth()-$pdf->getMargins()['right'], $pdf->GetY()+10);        
                
                ini_set('memory_limit', '2G');
    
                    
                $client_details = getClientsDetails($client_id);
                $client_language = $client_details['language'];
    
                if($client_language == "arabic"){
                    /* $html = file_get_contents(ROOTDIR .'/modules/addons/domaincontract/contract/contract.html'); */
                    $Arabic_contract = Capsule::table('tbladdonmodules')->where(['module'=> "domaincontract" , 'setting'=>"Arabic"])->value('value');
                    $html = file_get_contents($Arabic_contract);
                    $pdf->setRTL(true);                
                    $pdf->SetFont('aealarabiya', '', 14);
    
                }else{
                    $English_contract = Capsule::table('tbladdonmodules')->where(['module'=> "domaincontract" , 'setting'=>"English"])->value('value');
                    $html = file_get_contents($English_contract); 
                    $pdf->SetFont('dejavusans', '', 14);
                }
                
                $table_html = $html;
                
                $SystemURL = $CONFIG['SystemURL'];
                $curl_url = $SystemURL.'/DomainGetWhoisInfo.php?domainid='.$domainid;
                
                $DomainGetWhoisInfo = $helper->__curlCall($curl_url , "DomainGetWhoisInfo" );
                $domainWhoisInfo = json_decode($DomainGetWhoisInfo);
                $result_domian_info = $domainWhoisInfo->result;
                if($result_domian_info != "success"){
                    throw new Exception($domainWhoisInfo->result);
                }
                
               
                $customer_company_name = $domainWhoisInfo->Admin->Company_Name;
                $admin_contact_name = $domainWhoisInfo->Admin->Contact_Name;
                $mobile_number = $domainWhoisInfo->Admin->Mobile;
                $email = $domainWhoisInfo->Admin->Email;
                
    
                $signature = Capsule::table('tbladdonmodules')->where(['module'=> "domaincontract" , 'setting'=>"Signature Image"])->value('value');
    
            
                $table_html = str_replace('{date}', date('d-m-Y'), $table_html);
                $table_html = str_replace('{your company name}', "Sahara Net", $table_html);
                $table_html = str_replace('{COSTOMER COMPANY NAME}', $customer_company_name, $table_html);
                $table_html = str_replace('{ADMIN CONTACT NAME}', $admin_contact_name, $table_html);
                $table_html = str_replace('{xxxxxxxxxx}', $mobile_number, $table_html);
                $table_html = str_replace('{xxxxxxxxxxxx}', $email, $table_html);
                $table_html = str_replace('{Domain.SA}', $domainname, $table_html);
                $table_html = str_replace('{signature}', $signature, $table_html);
                
                $pdf->AddPage("P", strtoupper('A4'));
                        
                $pdf->WriteHTML($table_html, true, false, true, false, ''); // correct way
                
                $uniqueFileName = "Domain_contract".$domainid. '_' .time() .'.pdf';
                
                
                $pdf->Output(ROOTDIR .'/attachments/'.$uniqueFileName, 'F');
                $pdf->Output(ROOTDIR .'/modules/addons/domaincontract/contractfiles/'.$uniqueFileName, 'F');
               
             
                $helper->updateAttachments($uniqueFileName);
   
    
                $postData = array(                            
                    'messagename' => 'Domain Contract',
                    'id'          => $userid       
                    
                );
                
                $results = localAPI('SendEmail', $postData);
                logModuleCall("Domain Contract", 'Domain Contract Email', [ 'postdata' => $postData], ['result'=>$results]);
         //       sleep(10);
                $helper->remove_attachments_downloads($uniqueFileName);
    
                Capsule::table('mod_domain_contract')->insert(
                    [
                        'userid' => $userid,
                        'domainid' => $domainid,
                        'domainname' => $domainname,
                        'filename' => $uniqueFileName
                        
                    ]
                );
                
                logModuleCall("Domain Contract", "Send Contract File", $vars,  $results);
    
            }
            
        } catch (Exception $e) {
            logModuleCall("Domain Contract", "Send Contract File", $vars,  ['error' =>$e->getMessage()]);
        }
    }
});


?>