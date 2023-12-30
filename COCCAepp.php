<?php
# Configuration array
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule as DB;
use WHMCS\Domains\DomainLookup\ResultsList;
use WHMCS\Domains\DomainLookup\SearchResult;
use WHMCS\Module\Registrar\COCCAepp\ApiClient;
use WHMCS\Module\Addon\coccaepp_logs\HelperFunction;

// Require any libraries needed for the module to function.
// require_once __DIR__ . '/path/to/library/loader.php';

// Also, perform any initialization required by the service's library.

function COCCAepp_MetaData()
{
    return array(
        'DisplayName' => 'CoCCA Registrar Module for WHMCS',
        'APIVersion' => '1.1',
    );
}

function coccaepp_schema()
{
	try
	{
		if (!DB::schema()->hasTable('cocca_save_domain_contact'))
		{
			DB::schema()->create('cocca_save_domain_contact', function ($table)
			{
				$table->increments('id');
				$table->integer('domainid');
				$table->integer('userid');
				$table->text('contactdetail');
				$table->text('auth_key');
				$table->text('otp');
                $table->text('new_mobile_otp');
                $table->text('new_email_otp');
			});
		}
		if (!DB::schema()->hasTable('cocca_save_nameserver'))
		{
			DB::schema()->create('cocca_save_nameserver', function ($table)
			{
				$table->increments('id');
				$table->integer('domainid');
				$table->integer('userid');
				$table->text('nameserverdetail');
				$table->text('auth_key');
				$table->text('otp');
			});
		}
		if (!DB::schema()->hasTable('cocca_save_dnssec'))
		{ 
			DB::schema()->create('cocca_save_dnssec', function ($table)
			{
				$table->increments('id');
				$table->integer('domainid');
				$table->integer('userid');
				$table->text('dnssecdetail');
				$table->text('auth_key');
				$table->text('otp');
			}); 
		}
		if (!DB::schema()->hasTable('cocca_save_domains'))
    	{ 
            DB::schema()->create('cocca_save_domains', function ($table)
     		{
				$table->increments('id');
				$table->integer('domainid');
				$table->integer('userid');
				$table->text('auth_key');
				$table->text('otp');
				$table->integer('period')->default(0);
				$table->integer('status')->default(0);
				$table->string('type',50);
			});
        }
		
	}
	catch (Exception $e)
	{
        logActivity("Unable to create table:".$e->getMessage());
	}
}

function coccaepp_insertData($tablename, $insertdata, $userid)
{
	try
	{
		return DB::table($tablename)->insertGetId($insertdata);
	}
	catch (Exception $e)
	{
        logActivity("Unable to insert : ".$e->getMessage(), $userid);
	}
}

function COCCAepp_getConfigArray()
{
    coccaepp_schema();
        
	$configarray = array(
		"Username" => array( "Type" => "text", "Size" => "20", "Description" => "Enter your EPP username here" ),
		"Password" => array( "Type" => "password", "Size" => "20", "Description" => "Enter your EPP password here" ),
		"Server" => array( "Type" => "text", "Size" => "20", "Description" => "Enter EPP Server Address" ),
		"Port" => array( "Type" => "text", "Size" => "20", "Description" => "Enter EPP Server Port" ),
		"SSL" => array( "Type" => "yesno",'Description' => "Tick to enable" ),
		"Certificate" => array( "Type" => "text", "Description" => "(Optional) Path of certificate .pem" )
	);
	return $configarray;
}

function COCCAepp_AdminCustomButtonArray()
{
	$buttonarray = array(
						"Approve Transfer" => "ApproveTransfer",
						"Cancel Transfer Request" => "CancelTransferRequest",
						"Reject Transfer" => "RejectTransfer",
                		"Update Additional fields"  => "UpdateFieldsAdditional",
                		"Activate Variants"  => "ActivateVariants",
                		"Delete Variants"  => "DeleteVariants",
                		"Restore Domain"  => "RestoreDomain",
	);
	return $buttonarray;
}

function COCCAepp_ClientAreaCustomButtonArray()
{
	$buttonarray = array(
		"Manage DNSSEC" => "ManageDNSSEC",
		//"Request Delete" => "DeleteDomain"
	);
	return $buttonarray;
}

function COCCAepp_DeleteDomain($params)
{
	global $CONFIG;
	global $_LANG;

    # Grab variables
    $sld = $params["sld"];
    $tld = $params["tld"];
    $domain = "$sld.$tld";
    $domainid = $params['domainid'];

    $staus["status"] = 'success';

    $mailStatus =$dnssec = $authKey = $otpKey =	$updateStatus = " ";

    $dnssec_edit = '';
    $editeddata = '';
    $type= "";

	
    /* - To check/create cocca_save_dnssec table -*/
    coccaepp_schema();
	
    /* ---------Form Handling ----------*/

    //for all type request checkingt if any request is already pensing
    if($_REQUEST['a'] == 'DeleteDomain')
    {
        #check already exist
        $where = [['domainid', '=', $params["domainid"]]];

        if(!empty(DB::table('cocca_save_dnssec')->where($where)->count()))
        {
            $domaindelete = 'Pending Request';
            return array(
                    'templatefile' => 'deletedomain',
                    'requirelogin' => false,
                    'breadcrumb' => array(
                    	'clientarea.php?action=domaindetails&domainid='.$domainid.'&modop=custom&a=managednssec' => 'Manage DNSSEC',
                    ),
                    'vars' => array(
                    	    'domaindelete' => $domaindelete,
                        	'status' => $staus,
                        	'domainid' => $domainid,
                        	'otpKey' => $otpKey,
                        	'authKey' => $authKey,
                        	'type' => $type,
                        	//'dnssec_data' => $dnssec_data,
                        	//'dnssec_edit' => $dnssec_edit,
                        	//'new_dnssec_data' => $editeddata,
                ),
            );
		}
		else
		{ 
			//send mail to delete Domain 

            #Generate Random otp + authkey
            $otp = (string)mt_rand(111111, 999999);
            $encryptauthkey = substr(md5(time()), 0, 16);

            $myparams = array('action'=> "deletedomain");

            $data = serialize($myparams);
            //Insert Custom data
            $insert = array(
                        'domainid' => $params["domainid"],
                        'userid'=> $params["userid"],
                        'dnssecdetail'=> $data, 
                        'auth_key'=> $encryptauthkey,
                        'otp'=> $otp);

            #Email send
            $msg = $_LANG['dear_administrator'].",<br><br><b>".
			$_LANG['for_your_deletion_request_email_statement']."<br><br><br>";
			$msg .= "<a href='".$CONFIG['SystemURL']."/clientarea.php?action=domaindetails&id=".$params['domainid']."&modop=custom&a=ManageDNSSEC&userid=".$params['userid']."&authkey=".urlencode($encryptauthkey)."' target='_blank'><button type='button'>".$_LANG['click_to_proceed']."</button></a></b>";

            $getConatct =   COCCAepp_GetContactDetails($params);
            $adminEmail =  $getConatct['Admin']['Email'];
            $subject = $_LANG['domain_delete_record_request'];
            include_once dirname(__FILE__) . '/mail.php';
            $status = emailtouser($adminEmail, $subject, $msg);

            if($status)
            {
                $mailStatus = "Sent";
                $a = coccaepp_insertData('cocca_save_dnssec', $insert, $params["userid"]);
                logActivity("***--Delete domain, Email has been sent successfully.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
                HelperFunction::coccaepp_approval_logModuleCall($params['domainname'], 'Send Email: Delete domain', 'Delete domain','Email Sent Success');
            }
            else
            {
				$staus["status"] = 'Delete domain request has been failed.Please contact support.';
                $mailStatus = "Not Sent";
                logActivity("***--Domain Delete Email has been failed.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
                HelperFunction::coccaepp_approval_logModuleCall($params['domainname'], 'Send Email: Delete domain failed', 'Delete domain','Email Sent failed');
			}
			//send OTP on Phone number
			include_once dirname(__FILE__) . '/send_sms.php';
			$countrycode=explode('.',$getConatct['Admin']['Mobile'])[0];
			$number=explode('.',$getConatct['Admin']['Mobile'])[1];
			$send = sendsms($countrycode, $number, $domain, $otp);
			if($send)
			{
				logActivity("***--Domain delete request OTP has been sent successfully.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
				 HelperFunction::coccaepp_approval_logModuleCall($params['domainname'], 'Send OTP SMS: Domain delete request sms OTP', 'Delete domain','SMS Sent Success');
			}
			else
			{
				logActivity("***--Domain delete request OTP send has been failed.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
				 HelperFunction::coccaepp_approval_logModuleCall($params['domainname'], 'Send OTP SMS: Domain delete request sms OTP failed', 'Delete domain','SMS Sent failed');
			}

            return array(
                    'templatefile' => 'deletedomain',
                    'requirelogin' => false,
                    'breadcrumb' => array(
                            'clientarea.php?action=domaindetails&domainid='.$domainid.'&modop=custom&a=deletedomain' => 'Request Delete Domain',
                    ),
                    'vars' => array(
                            'deletedomain' => $deletedomain,
                            //'dnssec_data' => $dnssec_data,
                            'status' => $staus,
                            'domainid' => $domainid,
                            'mailstatus' => $mailStatus,
                    ),
            );
        
		}
	}
	
    // if(($_POST['mode'] == 'delete_dnssec'))
    // {
		
    //     #check already exist
    //     $where = [
    //         ['domainid', '=', $params["domainid"]],
    //     ];

    //     $getdata = DB::table('cocca_save_dnssec')->where($where)->first();

    //     $data = unserialize($getdata->dnssecdetail);

    //     if($data['action'] == "delete")
    //     {
    //         $dnssec = 'Pending Request';

    //         return array(
    //                 'templatefile' => 'managednssec',
    //                 'breadcrumb' => array(
    //                     'clientarea.php?action=domaindetails&domainid='.$domainid.'&modop=custom&a=managednssec' => 'Manage DNSSEC',
    //                 ),
    //                 'vars' => array(
    //                     'dnssec' => $dnssec,
    //                     'requirelogin' => false,
    //                     //'dnssec_data' => $dnssec_data,
    //                     'status' => $staus,
    //                     'domainid' => $domainid,
    //                     'dnssec_edit' => $dnssec_edit,
    //                     'otpKey' => $otpKey,
    //                     'authKey' => $authKey,
    //                     'type' => $type,
    //                     'new_dnssec_data' => $editeddata,
    //                 ),
    //             );
    //     }
    //     else
    //     {
    //         #delete mail sending

    //         #Generate Random otp + authkey
    //         $otp = (string)mt_rand(111111, 999999);
    //         $encryptauthkey = substr(md5(time()), 0, 16);

    //         $myparams = array('action'=> "delete");

    //         $data = serialize($myparams);
    //         //Insert Custom data
    //         $insert = array(
    //                     'domainid' => $params["domainid"],
    //                     'userid'=> $params["userid"],
    //                     'dnssecdetail'=> $data,
    //                     'auth_key'=> $encryptauthkey,
    //                     'otp'=> $otp);

    //         #Email send
    //         $msg = "Dear Administrator,<br><br><b>
    //             For your deletion request click the link below and fill the OTP (One Time Password )
    //             which you recieve on your registred mobile number.<br><br><br>";
	        // $msg .= "Your One Time Password (OTP) is : ".$otp."<br><br>";
	//			$msg .= "<a href='".$CONFIG['SystemURL']."/clientarea.php?action=domaindetails&id=".$params['domainid']."&modop=custom&a=ManageDNSSEC&userid=".$params['userid']."&authkey=".urlencode($encryptauthkey)."' target='_blank'><button type='button'>Click to proceed</button></a></b>";

    //         $getConatct =   COCCAepp_GetContactDetails($params);
    //         $adminEmail =  $getConatct['Admin']['Email'];
    //         $subject="DNSSEC Delete Record Request";
    //         include_once dirname(__FILE__) . '/mail.php';
    //         $status = emailtouser($adminEmail, $subject, $msg);

    //         if($status)
    //         {
    //             $mailStatus = "Sent";
    //             $a = coccaepp_insertData('cocca_save_dnssec', $insert, $params["userid"]);
    //             logActivity("***--Domain DNS Secure Email for delete record has been sent.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
    //         }
    //         else
    //         {
    //             $mailStatus = "Not Sent";
    //             logActivity("***--Domain DNS Secure Email for delete record has been failed.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
    //         }

    //         return array(
    //                 'templatefile' => 'managednssec',
    //                 'requirelogin' => false,
    //                 'breadcrumb' => array(
    //                         'clientarea.php?action=domaindetails&domainid='.$domainid.'&modop=custom&a=managednssec' => 'Manage DNSSEC',
    //                 ),
    //                 'vars' => array(
    //                         'dnssec' => $dnssec,
    //                         'dnssec_data' => $dnssec_data,
    //                         'status' => $staus,
    //                         'domainid' => $domainid,
    //                         'mailstatus' => $mailStatus,
    //                 ),
    //         );
    //     }
	// }
    
    // else if(($_POST['mode'] == 'update_dnssec'))
    // {

    //     $mydata = array();

    //     if($_POST["old_keyTag"] != $_POST["keyTag"])
    //     {
    //         $mydata['keyTag'] = $_POST["keyTag"];
    //     }
    //     if($_POST["old_alg"] != $_POST["alg"])
    //     {
    //         $mydata['alg'] = $_POST["alg"];
    //     }
    //     if($_POST["old_digestType"] != $_POST["digestType"])
    //     {
    //         $mydata['digestType'] = $_POST["digestType"];
    //     }
    //     if($_POST["old_digest"] != $_POST["digest"])
    //     {
    //         $mydata['digest'] = $_POST["digest"];
    //     }

    //     if(!empty($mydata))
    //     {
    //         $otp = (string)mt_rand(111111, 999999);
    //         $encryptauthkey = substr(md5(time()), 0, 16);

    //         $mydata['action'] = "edit";

    //         $data = serialize($mydata);
    //         //Insert Custom data
    //         $insert = array(
    //                         'domainid' => $params["domainid"],
    //                         'userid'=> $params["userid"],
    //                         'dnssecdetail'=> $data,
    //                         'auth_key'=> $encryptauthkey,
    //                         'otp'=> $otp
    //                 );

    //         #Email send
    //         $msg = "Dear Administrator,<br><br><b>
    //                         For your updation request click the link below and fill the OTP (One Time Password )
    //                         which you recieve on your registred mobile number.<br><br><br>";
	        // $msg .= "Your One Time Password (OTP) is : ".$otp."<br><br>";
	//			$msg .= "<a href='".$CONFIG['SystemURL']."/clientarea.php?action=domaindetails&id=".$params['domainid']."&modop=custom&a=ManageDNSSEC&userid=".$params['userid']."&authkey=".urlencode($encryptauthkey)."' target='_blank'><button type='button'>Click to proceed</button></a></b>";

    //         $getConatct =   COCCAepp_GetContactDetails($params);
    //         $adminEmail =  $getConatct['Admin']['Email'];
    //         $subject="DNSSEC Updation Record Request";
    //         include_once dirname(__FILE__) . '/mail.php';
    //         $status = emailtouser($adminEmail, $subject, $msg);

    //         if($status)
    //         {
    //                 $mailStatus = "Sent";
    //                 $a = coccaepp_insertData('cocca_save_dnssec', $insert, $params["userid"]);
    //                 logActivity("***--Domain DNS Secure updation request Email has been sent.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
    //         }
    //         else
    //         {
    //                 $mailStatus = "Not Sent";
    //                 logActivity("***--Domain DNS Secure updation request Email has been failed.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
    //         }

    //         return array(
    //                 'templatefile' => 'managednssec',
    //             'requirelogin' => false,
    //                 'breadcrumb' => array(
    //                         'clientarea.php?action=domaindetails&domainid='.$domainid.'&modop=custom&a=managednssec' => 'Manage DNSSEC',
    //                 ),
    //                 'vars' => array(
    //                         'dnssec' => $dnssec,
    //                         'dnssec_data' => $dnssec_data,
    //                         'status' => $staus,
    //                         'domainid' => $domainid,
    //                         'mailstatus' => $mailStatus,
    //                 ),
    //         );
    //     }
    //     else
    //     {
    //         //no changes. define new varibales to show msg
    //         return array(
    //                 'templatefile' => 'managednssec',
    //             'requirelogin' => false,
    //                 'breadcrumb' => array(
    //                         'clientarea.php?action=domaindetails&domainid='.$domainid.'&modop=custom&a=managednssec' => 'Manage DNSSEC',
    //                 ),
    //                 'vars' => array(
    //                         'dnssec' => $dnssec,
    //                         'dnssec_data' => $dnssec_data,
    //                         'status' => array('status'=>'No changes has been added.'),
    //                         'domainid' => $domainid,
    //                         'mailstatus' => $mailStatus,
    //                 ),
    //         );
    //     }

    //         /* ------------------< End Update >--------------*/
    // }
    // else if(($_POST['mode'] == 'verify_otp'))
    // {
    //     $where = [
    //                 ['domainid', '=', $params["domainid"]],
    //                 ['userid', '=', $params["userid"]],
    //             ];
    //     $get = DB::table('cocca_save_dnssec')->where($where)->first();


    //     if($_POST["otp"] == $get->otp)
    //     {
    //         $otpKey = 'otpKey Verified';
    //         $data = unserialize($get->dnssecdetail);

    //         if($data['action'] == "edit")
    //         {
    //             //edit
    //             $editeddata = $data;
    //             $type = 'edit';
    //         }
    //         elseif($data['action'] == "delete")
    //         {
    //             //delete
    //             $type = 'delete';
    //         }
    //         elseif($data['action'] == "add")
    //         {
    //             //add
    //             $type = 'add';
    //         }

    //         $dnssec_data['keyTag'] = $data['keyTag'];

    //         $dnssec_data['alg'] = $data['alg'];

    //         $dnssec_data['digestType'] = $data['digestType'];

    //         $dnssec_data['digest'] =  $data['digest'];
    //     }
    //     else
    //     {
    //         $otpKey = 'otpKey not Verified';
    //     }



    //         /* ------------------< End verify_otp >--------------*/
    // }
    // else if(($_POST['mode'] == 'accept_dnssec'))
    // {
    //     //pending
    //         /* ------------------<Reserve to code >--------------*/
    //     $status = DB::table('cocca_save_dnssec')->where("domainid",$params["domainid"])->delete();

    //     if($status)
    //     {
    //         $updateStatus = 'Rejected';
    //         logActivity("***--Request has been rejected.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
    //     }

    //     return array(
    //             'templatefile' => 'managednssec',
    //         'requirelogin' => false,
    //             'breadcrumb' => array(
    //                     'clientarea.php?action=domaindetails&domainid='.$domainid.'&modop=custom&a=managednssec' => 'Manage DNSSEC',
    //             ),
    //             'vars' => array(
    //                     'dnssec' => $dnssec,
    //                     'dnssec_data' => $dnssec_data,
    //                     'status' => $staus,
    //                     'domainid' => $domainid,
    //                     'updateStatus' => $updateStatus,
    //             ),
    //     );
    //         /* ------------------< End accept >--------------*/
    // }
    if($_POST['mode'] == 'reject_domaindelete')
    {
        $status = DB::table('cocca_save_dnssec')->where("domainid",$params["domainid"])->delete();

        if($status)
        {
            $updateStatus = 'Rejected';
            logActivity("***--Request has been cancelled.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
        }

        return array(
                'templatefile' => 'deletedomain',
     		    'requirelogin' => false,
                'breadcrumb' => array(
                        'clientarea.php?action=domaindetails&domainid='.$domainid.'&modop=custom&a=deletedomain' => 'Delete Domain',
                	),
                'vars' => array(
						'deletedomain' => $deletedomain,
                        'dnssec_data' => $dnssec_data,
                        'status' => $staus,
                        'domainid' => $domainid,
                        'updateStatus' => $updateStatus,
            ),
       	);
        /* ------------------< End reject >--------------*/
	}
	
    /*-------form handling closed---*/

    /*---auth key handle---*/
    if(!empty($_REQUEST["authkey"]) && !($_POST['mode'] == 'accept_dnssec_add' || $_POST['mode'] == 'accept_dnssec_edit' || $_POST['mode'] == 'accept_dnssec_delete'))
    {

        $where = [
                    ['domainid', '=', $params["domainid"]],
                    ['userid', '=', $params["userid"]],
                    ['auth_key', '=', $_GET["authkey"]],
                ];

        $count = DB::table('cocca_save_dnssec')->where($where)->count();

        if($count)
        {
            $authKey = 'authKey Verified';
        }
        else
        {
            $authKey = 'authKey not Verified';
        }

        return array(
                'templatefile' => 'managednssec',
            'requirelogin' => false,
                'breadcrumb' => array(
                        'clientarea.php?action=domaindetails&domainid='.$domainid.'&modop=custom&a=managednssec' => 'Manage DNSSEC',
                ),
                'vars' => array(
                        'dnssec' => $dnssec,
                        'dnssec_data' => $dnssec_data,
                        'status' => $staus,
                        'domainid' => $domainid,
                        'authKey' => $authKey,
                ),
        );
    }

    /*----auth key closed-----*/

    /*-----------general codeing--*/
    # Get client instance
    
}

function COCCAepp_ManageDNSSEC($params)
{
    global $CONFIG;
	global $_LANG;

    # Grab variables
    $sld = $params["sld"];
    $tld = $params["tld"];
    $domain = "$sld.$tld";
    $domainid = $params['domainid'];

    $staus["status"] = 'success';

    $mailStatus =$dnssec = $authKey = $otpKey =	$updateStatus = " ";

    $dnssec_edit = '';
    $editeddata = '';
    $type= "";

	/* - To check/create cocca_save_dnssec table -*/
    coccaepp_schema();


    /* ---------Form Handling ----------*/

    //for all type request checkingt if any request is already pensing
    if($_POST['mode'] == 'delete_dnssec' || $_POST['mode'] == 'edit_dnssec' || $_POST['mode'] == 'add_dnssec')
    {
        #check already exist
        $where = [['domainid', '=', $params["domainid"]]];

        if(!empty(DB::table('cocca_save_dnssec')->where($where)->count()))
        {
            $dnssec = 'Pending Request';

            return array(
                    'templatefile' => 'managednssec',
                    'requirelogin' => false,
                    'breadcrumb' => array(
                        'clientarea.php?action=domaindetails&domainid='.$domainid.'&modop=custom&a=managednssec' => 'Manage DNSSEC',
                    ),
                    'vars' => array(
                        'dnssec' => $dnssec,
                        'status' => $staus,
                        'domainid' => $domainid,
                        'dnssec_edit' => $dnssec_edit,
                        'otpKey' => $otpKey,
                        'authKey' => $authKey,
                        'type' => $type,
                        'new_dnssec_data' => $editeddata,
                        //'dnssec_data' => $dnssec_data,
                    ),
                );
        }
    }

    if(($_POST['mode'] == 'delete_dnssec'))
    {
        #check already exist
        $where = [['domainid', '=', $params["domainid"]]];

        $getdata = DB::table('cocca_save_dnssec')->where($where)->first();

        $data = unserialize($getdata->dnssecdetail);

        if($data['action'] == "delete")
        {
            $dnssec = 'Pending Request';

            return array(
                    'templatefile' => 'managednssec',
                    'breadcrumb' => array(
                        'clientarea.php?action=domaindetails&domainid='.$domainid.'&modop=custom&a=managednssec' => 'Manage DNSSEC',
                    ),
                    'vars' => array(
                        'dnssec' => $dnssec,
                        'requirelogin' => false,
                        'status' => $staus,
                        'domainid' => $domainid,
                        'dnssec_edit' => $dnssec_edit,
                        'otpKey' => $otpKey,
                        'authKey' => $authKey,
                        'type' => $type,
                        'new_dnssec_data' => $editeddata,
                        //'dnssec_data' => $dnssec_data,
                    ),
                );
        }
        else
        {
            #delete mail sending
			#Generate Random otp + authkey
			
            $otp = (string)mt_rand(111111, 999999);
            $encryptauthkey = substr(md5(time()), 0, 16);

            $myparams = array('action'=> "delete");

            $data = serialize($myparams);
            //Insert Custom data
            $insert = array(
                        'domainid' => $params["domainid"],
                        'userid'=> $params["userid"],
                        'dnssecdetail'=> $data,
                        'auth_key'=> $encryptauthkey,
                        'otp'=> $otp);

            #Email send
            $msg =  $_LANG['dear_administrator']."<br><br><b>
	                ".$_LANG['for_your_deletion_request_email_statement']."<br><br><br>";
			$msg .= "<a href='".$CONFIG['SystemURL']."/clientarea.php?action=domaindetails&id=".$params['domainid']."&modop=custom&a=ManageDNSSEC&userid=".$params['userid']."&authkey=".urlencode($encryptauthkey)."' target='_blank'><button type='button'>".$_LANG['click_to_proceed']."</button></a></b>";

            $getConatct =   COCCAepp_GetContactDetails($params);
            $adminEmail =  $getConatct['Admin']['Email'];
            $subject = $_LANG['dnssec_delete_record_request'];
            include_once dirname(__FILE__) . '/mail.php';
            $status = emailtouser($adminEmail, $subject, $msg);

            if($status)
            {
                $mailStatus = "Sent";
                $a = coccaepp_insertData('cocca_save_dnssec', $insert, $params["userid"]);
                logActivity("***--Domain DNS Secure Email for delete record has been sent successfully.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
                 HelperFunction::coccaepp_approval_logModuleCall($params['domainname'], 'Send Email: Domain DNS Secure Email for delete record', 'Delete DNS record','Email Sent Success');
            }
            else
            {
                $mailStatus = "Not Sent";
                logActivity("***--Domain DNS Secure Email for delete record has been failed.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
                HelperFunction::coccaepp_approval_logModuleCall($params['domainname'], 'Send Email failed: Domain DNS Secure Email for delete record', 'Delete DNS record','Email Sent failed');
			}
			//send OTP on Phone number
			include_once dirname(__FILE__) . '/send_sms.php';
			$countrycode=explode('.',$getConatct['Admin']['Mobile'])[0];
			$number=explode('.',$getConatct['Admin']['Mobile'])[1];
			$send = sendsms($countrycode, $number, $domain, $otp);
			if($send)
			{
				logActivity("***--Delete domain DNS secure request OTP has been sent successfully.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
				HelperFunction::coccaepp_approval_logModuleCall($params['domainname'], 'Send SMS: Domain DNS Secure SMS OTP for delete record', 'Delete DNS record','SMS Sent Success');
			}
			else
			{
				logActivity("***--Delete domain DNS secure request OTP send has been failed.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
					HelperFunction::coccaepp_approval_logModuleCall($params['domainname'], 'Send SMS failed: Domain DNS Secure SMS OTP for delete record', 'Delete DNS record','SMS Sent failed');
			}

            return array(
                    'templatefile' => 'managednssec',
                    'requirelogin' => false,
                    'breadcrumb' => array(
                            'clientarea.php?action=domaindetails&domainid='.$domainid.'&modop=custom&a=managednssec' => 'Manage DNSSEC',
                    	),
                    'vars' => array(
                            'dnssec' => $dnssec,
                            'dnssec_data' => $dnssec_data,
                            'status' => $staus,
                            'domainid' => $domainid,
                            'mailstatus' => $mailStatus,
                    ),
            );
        }
    }
    else if(($_POST['mode'] == 'edit_dnssec'))
    {
        #check already exist
        $where = [['domainid', '=', $params["domainid"]]];

        $getdata = DB::table('cocca_save_dnssec')->where($where)->first();

        $data = unserialize($getdata->dnssecdetail);

        if($data['action'] == "edit")
        {
            $dnssec = 'Pending Request';

            return array(
                    'templatefile' => 'managednssec',
                	'requirelogin' => false,
                    'breadcrumb' => array(
                        'clientarea.php?action=domaindetails&domainid='.$domainid.'&modop=custom&a=managednssec' => 'Manage DNSSEC',
                    ),
                    'vars' => array(
                        'dnssec' => $dnssec,
                        'status' => $staus,
                        'domainid' => $domainid,
                        'dnssec_edit' => $dnssec_edit,
                        'otpKey' => $otpKey,
                        'authKey' => $authKey,
                        'type' => $type,
                        'new_dnssec_data' => $editeddata,
                        //'dnssec_data' => $dnssec_data,
                    ),
                );
        }
        else
        {
            //edit
            $dnssec_edit = 'edit';
        }

    }
    else if(($_POST['mode'] == 'add_dnssec'))
    {
        /*---------< Update DNSSEC >----------- */
	    #Generate Random otp + authkey
        $otp = (string)mt_rand(111111, 999999);
	    $encryptauthkey = substr(md5(time()), 0, 16);

	     $myparams = array('keyTag' => $_POST["keyTag"],
                        'alg' => $_POST["alg"],
                        'digestType' => $_POST["digestType"],
                        'digest' => $_POST["digest"],
                        'action'=> "add");

        $data = serialize($myparams);
        //Insert Custom data
        $insert = array(
            'domainid' => $params["domainid"],
            'userid'=> $params["userid"],
            'dnssecdetail'=> $data,
            'auth_key'=> $encryptauthkey,
            'otp'=> $otp
        );

	    #Email send
        $msg = $_LANG['dear_administrator'].",<br><br><b>".
				$_LANG['for_your_add_new_record_request_email_statement']."<br><br><br>";
		$msg .= "<a href='".$CONFIG['SystemURL']."/clientarea.php?action=domaindetails&id=".$params['domainid']."&modop=custom&a=ManageDNSSEC&userid=".$params['userid']."&authkey=".urlencode($encryptauthkey)."' target='_blank'><button type='button'>".$_LANG['click_to_proceed']."</button></a></b>";

        $getConatct =   COCCAepp_GetContactDetails($params);
        $adminEmail =  $getConatct['Admin']['Email'];
        $subject = $_LANG['dnssec_add_new_record_request'];
        include_once dirname(__FILE__) . '/mail.php';
        $status = emailtouser($adminEmail, $subject, $msg);

        if($status)
        {
            $mailStatus = "Sent";
            $a = coccaepp_insertData('cocca_save_dnssec', $insert, $params["userid"]);
            logActivity("***--Domain DNS Secure Email for Add New Record has been sent successfully.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
            	HelperFunction::coccaepp_approval_logModuleCall($params['domainname'], 'Send Email: Domain DNS Secure Email OTP for add new record', 'Add DNS record','Email Sent Success');
        }
        else
        {
            $mailStatus = "Not Sent";
            logActivity("***--Domain DNS Secure Email for Add New Record has been failed.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
            	HelperFunction::coccaepp_approval_logModuleCall($params['domainname'], 'Send Email failed: Domain DNS Secure Email OTP for add new record', 'Add DNS record','Email Sent Failed');
		}
		//send OTP on Phone number
		include_once dirname(__FILE__) . '/send_sms.php';
		$countrycode=explode('.',$getConatct['Admin']['Mobile'])[0];
		$number=explode('.',$getConatct['Admin']['Mobile'])[1];
		$send = sendsms($countrycode, $number, $domain, $otp);
		if($send)
		{
			logActivity("***--Add new domain DNS secure request OTP has been sent successfully.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
				HelperFunction::coccaepp_approval_logModuleCall($params['domainname'], 'Send SMS: Domain DNS Secure SMS OTP for add new record', 'Add DNS record','SMS Sent Success');
		}
		else
		{
			logActivity("***--Add new domain DNS secure request OTP send has been failed.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
				HelperFunction::coccaepp_approval_logModuleCall($params['domainname'], 'Send SMS failed: Domain DNS Secure SMS OTP for add new record', 'Add DNS record','SMS Sent failed');
		}

        return array(
                'templatefile' => 'managednssec',
        	    'requirelogin' => false,
                'breadcrumb' => array(
                        'clientarea.php?action=domaindetails&domainid='.$domainid.'&modop=custom&a=managednssec' => 'Manage DNSSEC',
                ),
                'vars' => array(
                        'dnssec' => $dnssec,
                        'dnssec_data' => $dnssec_data,
                        'status' => $staus,
                        'domainid' => $domainid,
                        'mailstatus' => $mailStatus,
                ),
        );

		/* ------------------< End add >--------------*/
    }
    else if(($_POST['mode'] == 'update_dnssec'))
    {

        $mydata = array();

        if($_POST["old_keyTag"] != $_POST["keyTag"])
        {
            $mydata['keyTag'] = $_POST["keyTag"];
        }
        if($_POST["old_alg"] != $_POST["alg"])
        {
            $mydata['alg'] = $_POST["alg"];
        }
        if($_POST["old_digestType"] != $_POST["digestType"])
        {
            $mydata['digestType'] = $_POST["digestType"];
        }
        if($_POST["old_digest"] != $_POST["digest"])
        {
            $mydata['digest'] = $_POST["digest"];
        }

        if(!empty($mydata))
        {
            $otp = (string)mt_rand(111111, 999999);
            $encryptauthkey = substr(md5(time()), 0, 16);

            $mydata['action'] = "edit";

            $data = serialize($mydata);
            //Insert Custom data
            $insert = array(
                            'domainid' => $params["domainid"],
                            'userid'=> $params["userid"],
                            'dnssecdetail'=> $data,
                            'auth_key'=> $encryptauthkey,
                            'otp'=> $otp
                    );

            #Email send
            $msg = $_LANG['dear_administrator'].",<br><br><b>".
			$_LANG['for_your_updation_request_email_statement']."<br><br><br>";
			$msg .= "<a href='".$CONFIG['SystemURL']."/clientarea.php?action=domaindetails&id=".$params['domainid']."&modop=custom&a=ManageDNSSEC&userid=".$params['userid']."&authkey=".urlencode($encryptauthkey)."' target='_blank'><button type='button'>".$_LANG['click_to_proceed']."</button></a></b>";

            $getConatct =   COCCAepp_GetContactDetails($params);
            $adminEmail =  $getConatct['Admin']['Email'];
            $subject = $_LANG['dnssec_updation_record_request'];
            
            include_once dirname(__FILE__) . '/mail.php';
            $status = emailtouser($adminEmail, $subject, $msg);

            if($status)
            {
                    $mailStatus = "Sent";
                    $a = coccaepp_insertData('cocca_save_dnssec', $insert, $params["userid"]);
                    logActivity("***--Domain DNS Secure updation request Email has been sent successfully.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
                    	HelperFunction::coccaepp_approval_logModuleCall($params['domainname'], 'Send Email: Domain DNS Secure Email OTP for update record', 'update DNS record','Email Sent Success');
            }
            else
            {
                    $mailStatus = "Not Sent";
                    logActivity("***--Domain DNS Secure updation request Email has been failed.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
                    	HelperFunction::coccaepp_approval_logModuleCall($params['domainname'], 'Send Email failed: Domain DNS Secure Email OTP for update record', 'update DNS record','Email Sent failed');
                    	
			}
			//send OTP on Phone number
			include_once dirname(__FILE__) . '/send_sms.php';
			$countrycode=explode('.',$getConatct['Admin']['Mobile'])[0];
			$number=explode('.',$getConatct['Admin']['Mobile'])[1];
			$send = sendsms($countrycode, $number, $domain, $otp);
			if($send)
			{
				logActivity("***--Domain DNS Secure updation request OTP has been sent successfully.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
					HelperFunction::coccaepp_approval_logModuleCall($params['domainname'], 'Send SMS: Domain DNS Secure SMS OTP for update record', 'update DNS record','SMS Sent Success');
			}
			else
			{
				logActivity("***--Domain DNS Secure updation request OTP send has been failed.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
					HelperFunction::coccaepp_approval_logModuleCall($params['domainname'], 'Send SMS failed: Domain DNS Secure SMS OTP for update record', 'update DNS record','SMS Sent failed');
					
			}

            return array(
                    'templatefile' => 'managednssec',
            	    'requirelogin' => false,
                    'breadcrumb' => array(
                            'clientarea.php?action=domaindetails&domainid='.$domainid.'&modop=custom&a=managednssec' => 'Manage DNSSEC',
                    ),
                    'vars' => array(
                            'dnssec' => $dnssec,
                            'dnssec_data' => $dnssec_data,
                            'status' => $staus,
                            'domainid' => $domainid,
                            'mailstatus' => $mailStatus,
                    ),
            );
        }
        else
        {
            //no changes. define new varibales to show msg
            return array(
                    'templatefile' => 'managednssec',
                	'requirelogin' => false,
                    'breadcrumb' => array(
                            'clientarea.php?action=domaindetails&domainid='.$domainid.'&modop=custom&a=managednssec' => 'Manage DNSSEC',
                    ),
                    'vars' => array(
                            'dnssec' => $dnssec,
                            'dnssec_data' => $dnssec_data,
                            'status' => array('status'=>'No changes has been added.'),
                            'domainid' => $domainid,
                            'mailstatus' => $mailStatus,
                    ),
            );
        }

            /* ------------------< End Update >--------------*/
    }
    else if(($_POST['mode'] == 'verify_otp'))
    {
        $where = [
					['domainid', '=', $params["domainid"]],
                    ['userid', '=', $params["userid"]],
                ];
        $get = DB::table('cocca_save_dnssec')->where($where)->first();

        if($_POST["otp"] == $get->otp)
        {
            $otpKey = 'otpKey Verified';
            $data = unserialize($get->dnssecdetail);

            if($data['action'] == "edit")
            {
                //edit
                $editeddata = $data;
                $type = 'edit';
            }
            elseif($data['action'] == "delete")
            {
                //delete
                $type = 'delete';
            }
            elseif($data['action'] == "add")
            {
                //add
                $type = 'add';
            }

            $dnssec_data['keyTag'] = $data['keyTag'];

            $dnssec_data['alg'] = $data['alg'];

            $dnssec_data['digestType'] = $data['digestType'];

            $dnssec_data['digest'] =  $data['digest'];
        }
        else
        {
            $otpKey = 'otpKey not Verified';
        }
        /* ------------------< End verify_otp >--------------*/
    }
    else if(($_POST['mode'] == 'accept_dnssec'))
    {
		$status = DB::table('cocca_save_dnssec')->where("domainid",$params["domainid"])->delete();
        if($status)
        {
            $updateStatus = 'Rejected';
            logActivity("***--Request has been rejected.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
            
        }

        return array(
                'templatefile' => 'managednssec',
	            'requirelogin' => false,
                'breadcrumb' => array(
                        'clientarea.php?action=domaindetails&domainid='.$domainid.'&modop=custom&a=managednssec' => 'Manage DNSSEC',
                ),
                'vars' => array(
                        'dnssec' => $dnssec,
                        'dnssec_data' => $dnssec_data,
                        'status' => $staus,
                        'domainid' => $domainid,
                        'updateStatus' => $updateStatus,
                ),
        );
        /* ------------------< End accept >--------------*/
    }
    else if(($_POST['mode'] == 'reject_dnssec'))
    {
        $status = DB::table('cocca_save_dnssec')->where("domainid",$params["domainid"])->delete();

        if($status)
        {
            $updateStatus = 'Rejected';
            logActivity("***--Request has been rejected.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
        }

        return array(
                'templatefile' => 'managednssec',
            'requirelogin' => false,
                'breadcrumb' => array(
                        'clientarea.php?action=domaindetails&domainid='.$domainid.'&modop=custom&a=managednssec' => 'Manage DNSSEC',
                ),
                'vars' => array(
                        'dnssec' => $dnssec,
                        'dnssec_data' => $dnssec_data,
                        'status' => $staus,
                        'domainid' => $domainid,
                        'updateStatus' => $updateStatus,
                ),
        );
            /* ------------------< End reject >--------------*/
    }

    /*-------form handling closed---*/
    /*---auth key handle---*/
    if(!empty($_REQUEST["authkey"]) && !($_POST['mode'] == 'accept_dnssec_add' || $_POST['mode'] == 'accept_dnssec_edit' || $_POST['mode'] == 'accept_dnssec_delete'))
    { 
        $where = [
                    ['domainid', '=', $params["domainid"]],
                    ['userid', '=', $params["userid"]],
                    ['auth_key', '=', $_GET["authkey"]],
                ];

        $count = DB::table('cocca_save_dnssec')->where($where)->count();

        if($count)
        {
            $authKey = 'authKey Verified';
        }
        else
        {
            $authKey = 'authKey not Verified';
        }

        return array(
                'templatefile' => 'managednssec',
            	'requirelogin' => false,
                'breadcrumb' => array(
                        'clientarea.php?action=domaindetails&domainid='.$domainid.'&modop=custom&a=managednssec' => 'Manage DNSSEC',
                ),
                'vars' => array(
                        'dnssec' => $dnssec,
                        'dnssec_data' => $dnssec_data,
                        'status' => $staus,
                        'domainid' => $domainid,
                        'authKey' => $authKey,
                ),
        );
    }

    /*----auth key closed-----*/ 
    /*-----------general codeing--*/
    # Get client instance
    try
    {
	    $client = _COCCAepp_Client();

	    # Get list of dnssec for domain

        $where = [['domainid', '=', $params["domainid"]]];

        $getdata = DB::table('cocca_save_dnssec')->where($where)->first();

        $data = unserialize($getdata->dnssecdetail);

        if($_POST['mode'] == 'accept_dnssec_add')
        {
            $apiAction = 'Add DNS Sec Record';

            $where = [['domainid', '=', $params["domainid"]]];

            $getdata = DB::table('cocca_save_dnssec')->where($where)->first();

            $data = unserialize($getdata->dnssecdetail);

            $result = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
                                                <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
													<command xmlns="urn:ietf:params:xml:ns:epp-1.0">
														<update>
															<domain:update xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
																<domain:name>'.$domain.'</domain:name>
																<domain:add/>
																<domain:rem/>
																<domain:chg/>
															</domain:update>
														</update>
														<extension>
															<secDNS:update xmlns:secDNS="urn:ietf:params:xml:ns:secDNS-1.1">
																<secDNS:add>
																	<secDNS:dsData>
																		<secDNS:keyTag>'.$data['keyTag'].'</secDNS:keyTag>
																		<secDNS:alg>'.$data['alg'].'</secDNS:alg>
																		<secDNS:digestType>'.$data['digestType'].'</secDNS:digestType>
																		<secDNS:digest>'.$data['digest'].'</secDNS:digest>
																	</secDNS:dsData>
																</secDNS:add>
															</secDNS:update>
														</extension>
														<clTRID>'.mt_rand().mt_rand().'</clTRID>
													</command>
                                                </epp>');

            $result1 = '<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
							<response>
								<result code="1000">
									<msg>Command completed successfully</msg>
								</result>
								<resData>

								</resData> 

								<trID>
									<clTRID>1988270168912509933</clTRID>
									<svTRID>fbd1528a-7537-4f61-88bc-47891b57798c</svTRID>
								</trID>
							</response>
						</epp>';
        }
        else if($_POST['mode'] == 'accept_dnssec_edit')
        {
			$apiAction = 'Edit DNS Sec Record'; 
			
            $result = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
                                                <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
													<command xmlns="urn:ietf:params:xml:ns:epp-1.0">
														<update>
															<domain:update xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
																<domain:name>'.$domain.'</domain:name>
																<domain:add/>
																<domain:rem/>
																<domain:chg/>
															</domain:update>
														</update>
														<extension>
															<secDNS:updatexmlns:secDNS="urn:ietf:params:xml:ns:secDNS-1.1">
																<secDNS:rem>
																	<secDNS:all>true</secDNS:all>
																</secDNS:rem>
																<secDNS:add>
																	<secDNS:dsData>
																	<secDNS:keyTag>'.$data['keyTag'].'</secDNS:keyTag>
																	<secDNS:alg>'.$data['alg'].'</secDNS:alg>
																	<secDNS:digestType>'.$data['digestType'].'</secDNS:digestType>
																	<secDNS:digest>'.$data['digest'].'</secDNS:digest></secDNS:dsData>
																</secDNS:add>
															</secDNS:update>
														</extension>
														<clTRID>'.mt_rand().mt_rand().'</clTRID>
													</command>
                                                </epp>');

            $result1 = '<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
							<response>
								<result code="1000">
									<msg>Command completed successfully</msg>
								</result>
								<resData>

								</resData>

								<trID>
									<clTRID>1988270168912509933</clTRID>
									<svTRID>fbd1528a-7537-4f61-88bc-47891b57798c</svTRID>
								</trID>
							</response>
						</epp>';
        }
        else if($_POST['mode'] == 'accept_dnssec_delete')
        {
            /**----- get details-----**/
            
            $apiAction = 'GetDNS Sec Record';

            $result = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
													<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
														<command xmlns="urn:ietf:params:xml:ns:epp-1.0">
															<info>
																<domain:info xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
																	<domain:name hosts="all">'.$domain.'</domain:name>
																</domain:info>
															</info>
															<clTRID>'.mt_rand().mt_rand().'</clTRID>
														</command>
													</epp>
                                    			');

            # Parse XML result
            $doc = new DOMDocument();

            $doc->loadXML($result);

         /*   logModuleCall('COCCAepp',$apiAction, $xml, $result); */
         HelperFunction::coccaepp_logModuleCall('COCCAepp',$apiAction, $xml, $result);


            # Pull off status
            $coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
            $msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;

            
            if(!empty($doc->getElementsByTagName('keyTag')->item(0)->nodeValue))
            {
                $dnssec = 'Exist';

                $data['keyTag'] = $doc->getElementsByTagName('keyTag')->item(0)->nodeValue;

                $data['alg'] = $doc->getElementsByTagName('alg')->item(0)->nodeValue;

                $data['digestType'] =  $doc->getElementsByTagName('digestType')->item(0)->nodeValue;

                $data['digest'] =  $doc->getElementsByTagName('digest')->item(0)->nodeValue;
            }
            
             /*-------------*/
            $apiAction = 'Delete DNS Sec Record';

            $result = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
													<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
														<command xmlns="urn:ietf:params:xml:ns:epp-1.0">
															<update>
																<domain:update xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
																	<domain:name>'.$domain.'</domain:name>
																	<domain:add/>
																	<domain:rem/>
																	<domain:chg/>
																</domain:update>
															</update>
															<extension>
																<secDNS:update xmlns:secDNS="urn:ietf:params:xml:ns:secDNS-1.1">
																	<secDNS:rem>
																		<secDNS:dsData>
																			<secDNS:keyTag>'.$data['keyTag'].'</secDNS:keyTag>
																			<secDNS:alg>'.$data['alg'].'</secDNS:alg>
																			<secDNS:digestType>'.$data['digestType'].'</secDNS:digestType>
																			<secDNS:digest>'.$data['digest'].'</secDNS:digest>
																		</secDNS:dsData>
																	</secDNS:rem>
																</secDNS:update>
															</extension>
														<clTRID>'.mt_rand().mt_rand().'</clTRID>
														</command>
													</epp>');


            $result1 = '<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
							<response>
								<result code="1000">
									<msg>Command completed successfully</msg>
								</result>
								<resData>

								</resData>

								<trID>
									<clTRID>1988270168912509933</clTRID>
									<svTRID>fbd1528a-7537-4f61-88bc-47891b57798c</svTRID>
								</trID>
							</response>
						</epp>';
        }
        else
        {
            $apiAction = 'GetDNS Sec Record';

            $result = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
													<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
														<command xmlns="urn:ietf:params:xml:ns:epp-1.0">
															<info>
																<domain:info xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
																	<domain:name hosts="all">'.$domain.'</domain:name>
																</domain:info>
															</info>
															<clTRID>'.mt_rand().mt_rand().'</clTRID>
														</command>
													</epp>');

        }

        $result1 = '<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
						<response>
							<result code="1000">
								<msg>Command completed successfully</msg>
							</result>
							<resData>
								<domain:infData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
									<domain:name>testsahara-dnssec.sa</domain:name>
									<domain:roid>95949344927752f5-snic</domain:roid>
									<domain:status s="ok"/>
									<domain:registrant>12BER4fyYWBRZUD0</domain:registrant>
									<domain:contact type="admin">12BER4fyYWBRZUD0</domain:contact>
									<domain:contact type="billing">12BER4fyYWBRZUD0</domain:contact>
									<domain:contact type="tech">12BER4fyYWBRZUD0</domain:contact>
									<domain:ns>
										<domain:hostObj>ns.cocca.fr</domain:hostObj>
										<domain:hostObj>ns.cxda.org.cx</domain:hostObj>
									</domain:ns>
									<domain:clID>testsahara</domain:clID>
									<domain:crID>testsahara</domain:crID>
									<domain:crDate>2020-06-26T17:52:03Z</domain:crDate>
									<domain:upID>testsahara</domain:upID>
									<domain:upDate>2020-10-07T18:33:59Z</domain:upDate>
									<domain:exDate>2021-06-26T17:52:03Z</domain:exDate>
									<domain:authInfo>
										<domain:pw>BsMiWXM3whzPQvuDIBQp8DBnGhgnSp8d</domain:pw>
									</domain:authInfo>
								</domain:infData>
							</resData>
							<extension>
								<secDNS:infData xmlns:secDNS="urn:ietf:params:xml:ns:secDNS-1.1">
									<secDNS:dsData>
										<secDNS:keyTag>48715</secDNS:keyTag>
										<secDNS:alg>8</secDNS:alg>
										<secDNS:digestType>2</secDNS:digestType>
										<secDNS:digest>0C969F984F8BE2430D1874308B63928E7ED5C4729437ABB121AB28B4EF94B8D1</secDNS:digest>
									</secDNS:dsData>
								</secDNS:infData>
							</extension>
							<trID>
								<clTRID>1988270168912509933</clTRID>
								<svTRID>fbd1528a-7537-4f61-88bc-47891b57798c</svTRID>
							</trID>
						</response>
                	</epp>';

        # Parse XML result
        $doc = new DOMDocument();

        $doc->loadXML($result);

	/*	logModuleCall('COCCAepp',$apiAction, $xml, $result); */
		 HelperFunction::coccaepp_logModuleCall('COCCAepp',$apiAction, $xml, $result);

		# Pull off status
		$coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
		$msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;

		# Check the result is ok
		if(!eppSuccess($coderes))
		{
			#error
			$staus["status"] = $apiAction." /domain-info($domain): Code ($coderes) $msg";

			return array(
						'templatefile' => 'managednssec',
						'requirelogin' => false,
						'breadcrumb' => array(
							'clientarea.php?action=domaindetails&domainid='.$domainid.'&modop=custom&a=managednssec' => 'Manage DNSSEC',
							),
						'vars' => array(
							'status' => $staus,
						),
					);
		}

		if($_POST['mode'] == 'accept_dnssec_add' || $_POST['mode'] == 'accept_dnssec_edit' || $_POST['mode'] == 'accept_dnssec_delete')
		{
			$status = DB::table('cocca_save_dnssec')->where("domainid",$params["domainid"])->delete();

			logActivity("*--DNSSEC Request has been accepted for {$apiAction}.Domain ID: {$params['domainid']} User ID: {$params['userid']} --*");

			return array(
							'templatefile' => 'managednssec',
							'requirelogin' => false,
							'breadcrumb' => array(
									'clientarea.php?action=domaindetails&domainid='.$domainid.'&modop=custom&a=managednssec' => 'Manage DNSSEC',
								),
							'vars' => array(
								'status' => $staus,
								'acceptUpdate' =>'success'
							),
						);
		}
 
		if(!empty($doc->getElementsByTagName('keyTag')->item(0)->nodeValue))
		{
			$dnssec = 'Exist';

			$dnssec_data['keyTag'] = $doc->getElementsByTagName('keyTag')->item(0)->nodeValue;

			$dnssec_data['alg'] = $doc->getElementsByTagName('alg')->item(0)->nodeValue;

			$dnssec_data['digestType'] =  $doc->getElementsByTagName('digestType')->item(0)->nodeValue;

			$dnssec_data['digest'] =  $doc->getElementsByTagName('digest')->item(0)->nodeValue;
		}
		else
		{
            #check entry in table
            $where = [['domainid', '=', $params["domainid"]]];

            $getdata = DB::table('cocca_save_dnssec')->where($where)->first();

            if(!empty($getdata->otp) && !empty($getdata->auth_key))
            {
              	#already existy
              	$dnssec = 'Pending Request';
            }
            else
            {
              	$dnssec = 'Not Exist';
            }
        }

        if($dnssec_edit == 'edit' || $type == 'edit' || $type == 'delete' || $_POST['mode'] == 'verify_otp')
        {
          	$dnssec = '';
        }

        return array(
                    'templatefile' => 'managednssec',
            		'requirelogin' => false,
                    'breadcrumb' => array(
                        	'clientarea.php?action=domaindetails&domainid='.$domainid.'&modop=custom&a=managednssec' => 'Manage DNSSEC',
                    	),
                    'vars' => array(
                        'dnssec' => $dnssec,
                        'dnssec_data' => $dnssec_data,
                        'status' => $staus,
                        'domainid' => $domainid,
                        'dnssec_edit' => $dnssec_edit,
                        'otpKey' => $otpKey,
                        'authKey' => $authKey,
                        'type' => $type,
                        'new_dnssec_data' => $editeddata,
                    ),
                );
    }
    catch (Exception $e)
    {
		$staus["status"] = 'GetNameservers/EPP: '.$e->getMessage();

		return array(
					'templatefile' => 'managednssec',
					'breadcrumb' => array(
							'clientarea.php?action=domaindetails&domainid='.$domainid.'&modop=custom&a=managednssec' => 'Manage DNSSEC',
						),
					'vars' => array('status' => $staus)
				);
    }
}

# Function to return current nameservers
function COCCAepp_GetNameservers($params)
{
	# Grab variables
	$sld = $params["sld"];
	$tld = $params["tld"];
	$domain = "$sld.$tld";

	# Get client instance
	try
	{
		$client = _COCCAepp_Client();

		# Get list of nameservers for domain
		$result = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
												<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
													<command>
														<info>
															<domain:info xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
																<domain:name hosts="all">'.$domain.'</domain:name>
															</domain:info>
														</info>
														<clTRID>'.mt_rand().mt_rand().'</clTRID>
													</command>
												</epp>');

		# Parse XML result
		$doc = new DOMDocument();
		$doc->loadXML($result);
	/*	logModuleCall('COCCAepp', 'GetNameservers', $xml, $result); */
	 HelperFunction::coccaepp_logModuleCall('COCCAepp','GetNameservers', $xml, $result);

		# Pull off status
		$coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
		$msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;
		# Check the result is ok
		if(!eppSuccess($coderes))
		{
			$values["error"] = "GetNameservers/domain-info($domain): Code ($coderes) $msg";
			return $values;
		}

		# Grab hostObj array
        $ns = $doc->getElementsByTagName('hostObj');
        # Extract nameservers & build return result
        $i = 1;	$values = array();
		foreach ($ns as $nn)
		{
            $values["ns{$i}"] = $nn->nodeValue;
            $i++;
        }
        // $values["status"] = $msg;

        return $values;

	}
	catch (Exception $e)
	{
		$values["error"] = 'GetNameservers/EPP: '.$e->getMessage();
		return $values;
	}
	
	return $values;
}

# Function to save set of nameservers
function COCCAepp_SaveNameservers($params)
{
	global $CONFIG;
	global $_LANG;
        # Grab variables
        $sld = $params["sld"];
        $tld = $params["tld"];
        $domain = "$sld.$tld";

	  
	if($params["registrar"] == "COCCAepp" && $_POST['operation'] == 'save' && $_POST["nschoice"]  == 'custom')
	{
      	try
      	{
          	#Function call to create/check custom table "cocca_save_nameserver"
			coccaepp_schema();

			$where = [
						['domainid', '=', $params["domainid"]],
						['userid', '=', $params["userid"]],
					];

			$count = DB::table('cocca_save_nameserver')->where($where)->count();
			
			if($count)
			{
				return "Request is already pending for administrator approval";
			}

          	#Generate Random otp + authkey
          	$otp = (string)mt_rand(111111, 999999);
			$encryptauthkey = substr(md5(time()), 0, 16);

			#Email send
			$msg = $_LANG['dear_administrator'].",<br><br><b>".
			$_LANG['for_your_updation_request_email_statement']."<br><br><br>";
			$msg .= "<a href='".$CONFIG['SystemURL']."/updatenameserver.php?domainid=".$params['domainid']."&userid=".$params['userid']."&authkey=".urlencode($encryptauthkey)."' target='_blank'><button type='button'>".$_LANG['click_to_proceed']."</button></a></b>";

			$getConatct =   COCCAepp_GetContactDetails($params);
			$adminEmail =  $getConatct['Admin']['Email'];
			$subject = $_LANG['whois_update_approval_request_for_nameserver']." : ".$domain;

			include_once dirname(__FILE__) . '/mail.php';
			$status = emailtouser($adminEmail, $subject, $msg);


			if($status)
			{
				logActivity("***--Update Approval Request Email for Nameserver has been sent successfully.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
				HelperFunction::coccaepp_approval_logModuleCall($params['domainname'], 'Send Email: Update Nameserver', 'update Nameserver','Email Sent Success');
			}
			else
			{
				logActivity("***--Update Approval Request Email for Nameserver has been failed.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
				HelperFunction::coccaepp_approval_logModuleCall($params['domainname'], 'Send Email failed: Update Nameserver', 'update Nameserver','Email Sent failed');
			}

			//send OTP on Phone number
			include_once dirname(__FILE__) . '/send_sms.php';
			$countrycode=explode('.',$getConatct['Admin']['Mobile'])[0];
			$number=explode('.',$getConatct['Admin']['Mobile'])[1];
			$send = sendsms($countrycode, $number, $domain, $otp);
			if($send){
				logActivity("***--Nameserver update request OTP has been sent successfully. Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
					HelperFunction::coccaepp_approval_logModuleCall($params['domainname'], 'Send SMS: Update Nameserver', 'update Nameserver','SMS Sent Success');
			}
			else
			{
				logActivity("***--Nameserver update request send OTP has been failed.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
					HelperFunction::coccaepp_approval_logModuleCall($params['domainname'], 'Send SMS failed: Update Nameserver', 'update Nameserver','SMS Sent failed');
			}
			
			//Insert Custom data
			
			$data = serialize($params);
    		$insert = array(
              	'domainid' => $params["domainid"],
              	'userid' => $params["userid"],
              	'nameserverdetail' => $data,
              	'auth_key' => $encryptauthkey,
              	'otp' => $otp,
          	);
			
			coccaepp_insertData('cocca_save_nameserver', $insert, $params["userid"]);

          	return "success";
      	}
  		catch (Exception $e)
  		{
          	logActivity("ERROR :".$e->getMessage(), $params["userid"]);
    		return ["error" =>"ERROR :".$e->getMessage()];
  		}
	}
	else if($params["registrar"] == "COCCAepp" && $_POST['operation'] == 'cancel')
	{
      	try
      	{
			$where = [
						['domainid', '=', $params["domainid"]],
						['userid', '=', $params["userid"]]
					];
        	DB::table('cocca_save_nameserver')->where($where)->delete();
			logActivity("***--Update nameserver request has been cancelled.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
  		}
		catch (Exception $e)
		{
			logActivity("ERROR :".$e->getMessage(), $params["userid"]);
		}
  		return "success";
	}

	# Grab variables
	$sld = $params["sld"];
	$tld = $params["tld"];
	$domain = "$sld.$tld";

    # Generate array of new nameservers
    $nameservers=array();
	if (!empty($params["ns1"]))
	{
		array_push($nameservers,$params["ns1"]);
	}
	if (!empty($params["ns2"]))
	{
		array_push($nameservers,$params["ns2"]);
	}
	if(!empty($params["ns3"]))
	{
		array_push($nameservers,$params["ns3"]);
	}
	if(!empty($params["ns4"]))
	{
		array_push($nameservers,$params["ns4"]);
	}
	if(!empty($params["ns5"]))
	{
		array_push($nameservers,$params["ns5"]);
	}

	# Get client instance
	try
	{
		$client = _COCCAepp_Client($params);

		for($i=0; $i < count($nameservers); $i++)
		{
            # Get list of nameservers for domain
        	$result = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
													<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
														<command>
															<info>
																<host:info xmlns:host="urn:ietf:params:xml:ns:host-1.0">
																	<host:name>'.$nameservers[$i].'</host:name>
																</host:info>
															</info>
															<clTRID>'.mt_rand().mt_rand().'</clTRID>
														</command>
													</epp>');
            # Parse XML result
            $doc = new DOMDocument();
            $doc->preserveWhiteSpace = false;
            $doc->loadXML($result);
           /* logModuleCall('COCCAepp', 'GetNameservers', $xml, $result); */
             HelperFunction::coccaepp_logModuleCall('COCCAepp','GetNameservers', $xml, $result);

            # Pull off status
            $coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
            $msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;
            # Check if the nameserver exists in the registry...if not, add it
			if($coderes == '2303')
			{
                $request = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
														<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
															<command>
																<create>
																	<host:create xmlns:host="urn:ietf:params:xml:ns:host-1.0">
																		<host:name>'.$nameservers[$i].'</host:name>
																	</host:create>
																</create>
																<clTRID>'.mt_rand().mt_rand().'</clTRID>
															</command>
														</epp>');

                # Parse XML result
                $doc= new DOMDocument();
                $doc->loadXML($request);
              /*  logModuleCall('COCCAepp', 'SaveNameservers', $xml, $request); */
                 HelperFunction::coccaepp_logModuleCall('COCCAepp','SaveNameservers', $xml, $request);

                # Pull off status
                $coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
                $msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;
                # Check if result is ok
				if(!eppSuccess($coderes))
				{
                    $values["error"] = "Could not Create host($nameservers[$i]): Code ($coderes) $msg";
                    return $values;
                }
            }
        }
        # Generate XML for nameservers to add
		if ($nameserver1 = $params["ns1"])
		{
		    $add_hosts = '<domain:hostObj>'.$nameserver1.'</domain:hostObj>';
        }
		if ($nameserver2 = $params["ns2"])
		{
            $add_hosts .= '<domain:hostObj>'.$nameserver2.'</domain:hostObj>';
        }
		if ($nameserver3 = $params["ns3"])
		{
            $add_hosts .= '<domain:hostObj>'.$nameserver3.'</domain:hostObj>';
        }
		if ($nameserver4 = $params["ns4"])
		{
            $add_hosts .= '<domain:hostObj>'.$nameserver4.'</domain:hostObj>';
        }
		if ($nameserver5 = $params["ns5"])
		{
            $add_hosts .= '<domain:hostObj>'.$nameserver5.'</domain:hostObj>';
        }

        # Grab list of current nameservers
        $request = $client->request($xml='<?xml version="1.0" encoding="UTF-8" standalone="no"?>
											<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
												<command>
													<info>
														<domain:info xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
															<domain:name hosts="all">'.$domain.'</domain:name>
														</domain:info>
													</info>
													<clTRID>'.mt_rand().mt_rand().'</clTRID>
												</command>
											</epp>');

        # Parse XML result
        $doc= new DOMDocument();
        $doc->loadXML($request);
        # Pull off status
        $coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
        $msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;
        # Check if result is ok
		if(!eppSuccess($coderes))
		{
            $values["error"] = "SaveNameservers/domain-info($sld.$tld): Code ($coderes) $msg";
            return $values;
        }

        $values["status"] = $msg;

        # Generate list of nameservers to remove
        $hostlist = $doc->getElementsByTagName('hostObj');
        $rem_hosts = '';
		foreach ($hostlist as $host)
		{
            $rem_hosts .= '<domain:hostObj>'.$host->nodeValue.'</domain:hostObj>';
    	}

        # Build request
	    $request = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
												<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
													<command>
														<update>
															<domain:update xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
																<domain:name>'.$sld.'.'.$tld.'</domain:name>
																<domain:add>
																	<domain:ns>'.$add_hosts.' </domain:ns>
																</domain:add>
																<domain:rem>
																	<domain:ns>'.$rem_hosts.'</domain:ns>
																</domain:rem>
															</domain:update>
														</update>
														<clTRID>'.mt_rand().mt_rand().'</clTRID>
													</command>
												</epp>');
												
        # Parse XML result
        $doc= new DOMDocument();
        $doc->loadXML($request);
      /*  logModuleCall('COCCAepp', 'SaveNameservers', $xml, $request); */
         HelperFunction::coccaepp_logModuleCall('COCCAepp','SaveNameservers', $xml, $request);

        # Pull off status
        $coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
        $msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;
        # Check if result is ok
		if(!eppSuccess($coderes))
		{
            $values["error"] = "SaveNameservers/domain-update($sld.$tld): Code ($coderes) $msg";
            return $values;
        }

        $values['status'] = "Domain update Successful";

	}
	catch (Exception $e)
	{
		$values["error"] = 'SaveNameservers/EPP: '.$e->getMessage();
		return $values;
	}

	return $values;
}

function COCCAepp_GetRegistrarLock($params)
{
	# Grab variables
	$sld = $params["sld"];
	$tld = $params["tld"];
	$domain = "$sld.$tld";
	
	// what is the current domain status?
	# Grab list of current nameservers
	try
	{
		$client = _COCCAepp_Client();
		$request = $client->request($xml='<?xml version="1.0" encoding="UTF-8" standalone="no"?>
											<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
												<command>
													<info>
														<domain:info xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
															<domain:name hosts="all">'.$domain.'</domain:name>
														</domain:info>
													</info>
													<clTRID>'.mt_rand().mt_rand().'</clTRID>
												</command>
											</epp>');

		# Parse XML result
		$doc= new DOMDocument();
		$doc->loadXML($request);
	/*	logModuleCall('COCCAepp', 'GetRegistrarLock', $xml, $request); */
		 HelperFunction::coccaepp_logModuleCall('COCCAepp','GetRegistrarLock', $xml, $request);
		
		$coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
		$msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;
		# Check result
		if(!eppSuccess($coderes))
		{
			$lockstatus = "GetRegistrarLock for Domain:($sld.$tld): Code (".$coderes.") ".$msg;
			return  $lockstatus;
		}

		$statusarray = $doc->getElementsByTagName("status");
		$currentstatus = array();
		foreach ($statusarray as $nn)
		{
			$currentstatus[] = $nn->getAttribute("s");
		}
	}
	catch (Exception $e)
	{
		$values["error"] = $e->getMessage();
		return $values;
	}

	# Get lock status
	if (array_key_exists(array_search("clientDeleteProhibited", $currentstatus), $currentstatus) == 1 || array_key_exists(array_search("clientTransferProhibited", $currentstatus), $currentstatus) == 1 )
	{
		$lockstatus = "locked";
	}
	else
	{
		$lockstatus = "unlocked";
	}
	return $lockstatus;
}

function COCCAepp_SaveRegistrarLock($params)
{
	# Grab variables
	$sld = $params["sld"];
	$tld = $params["tld"];
	$domain = "$sld.$tld";

	if (COCCAepp_GetRegistrarLock($params) == "unlocked" && $params["lockenabled"] == "locked")
	{
		COCCAepp_LockDomain($params);
	}
	else
	{
		if (COCCAepp_GetRegistrarLock($params) == "locked"  && $params["lockenabled"] == "unlocked")
		{
			COCCAepp_UnlockDomain($params);
		}
		else
		{
			$values["error"] = "SaveRegistrar LOCK: Domain Status unknown ";
			return $values;
		}
	}
}

function COCCAepp_LockDomain($params)
{
	$sld = $params["sld"];
	$tld = $params["tld"];
	$domain = "$sld.$tld";

	try
	{
		if (!isset($client))
		{
            $client = _COCCAepp_Client();
        }

        # Lock Domain
        //First lock the less restrictive locks
		// <domain:status s="serverUpdateProhibited"/>
    	// <domain:status s="serverTransferProhibited"/>
		// <domain:status s="serverRenewProhibited"/>
		// <domain:status s="serverDeleteProhibited"/>
        $request = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
												<epp xmlns="urn:ietf:params:xml:ns:epp-1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
													<command>
														<update>
															<domain:update xmlns:domain="urn:ietf:params:xml:ns:domain-1.0" xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
																<domain:name>'.$sld.'.'.$tld.'</domain:name>
																<domain:add>
																	<domain:status s="clientDeleteProhibited"/>
																	<domain:status s="clientTransferProhibited"/>
																</domain:add>
															</domain:update>
														</update>
														<clTRID>'.mt_rand().mt_rand().'</clTRID>
													</command>
												</epp>');
		# Parse XML result
			$doc= new DOMDocument();
			$doc->loadXML($request);
		/*	logModuleCall('COCCAepp', 'Lock-Delete-Transfer', $xml, $request); */
			 HelperFunction::coccaepp_logModuleCall('COCCAepp','Lock-Delete-Transfer', $xml, $request);
			
			$coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
			$msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;
			# Check result
			if(!eppSuccess($coderes)) {
					$values["error"] = "Lock Domain($sld.$tld): Code (".$coderes.") ".$msg;
					return $values;
			}
	}
	catch (Exception $e)
	{
		$values["error"] = 'Domain Lock/EPP: '.$e->getMessage();
		return $values;
	}

	return $values;
}

function COCCAepp_UnlockDomain($params)
{
	# Grab variables
	$sld = $params["sld"];
	$tld = $params["tld"];
	$domain = "$sld.$tld";
	try
	{
		if (!isset($client))
		{
			$client = _COCCAepp_Client();
		}

		# Lift Update Prohibited Lock
		$request = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
												<epp xmlns="urn:ietf:params:xml:ns:epp-1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
													<command>
														<update>
															<domain:update xmlns:domain="urn:ietf:params:xml:ns:domain-1.0" xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
																<domain:name>'.$sld.'.'.$tld.'</domain:name>
																<domain:rem>
																	<domain:status s="clientUpdateProhibited"/>
																</domain:rem>
															</domain:update>
														</update>
														<clTRID>'.mt_rand().mt_rand().'</clTRID>
													</command>
												</epp>');
		# Parse XML result
		$doc= new DOMDocument();
		$doc->loadXML($request);
	/*	logModuleCall('COCCAepp', 'Remove UpdateProhibited', $xml, $request); */
		HelperFunction::coccaepp_logModuleCall('COCCAepp','Remove UpdateProhibited', $xml, $request);
			 

		$request = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
												<epp xmlns="urn:ietf:params:xml:ns:epp-1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
													<command>
														<update>
															<domain:update xmlns:domain="urn:ietf:params:xml:ns:domain-1.0" xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
																<domain:name>'.$sld.'.'.$tld.'</domain:name>
																<domain:rem>
																	<domain:status s="clientDeleteProhibited"/>
																	<domain:status s="clientTransferProhibited"/>
																	<domain:status s="clientRenewProhibited"/>
																</domain:rem>
															</domain:update>
														</update>
														<clTRID>'.mt_rand().mt_rand().'</clTRID>
													</command>
												</epp>');
		# Parse XML result
		$doc= new DOMDocument();
		$doc->loadXML($request);
	/*	logModuleCall('COCCAepp', 'Domain UnLock', $xml, $request); */
		HelperFunction::coccaepp_logModuleCall('COCCAepp','Domain UnLock', $xml, $request);

		$coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
		$msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;
		# Check result
		if(!eppSuccess($coderes))
		{
			$values["error"] = "Domain Unlock($sld.$tld): Code (".$coderes.") ".$msg;
			return $values;
		}
	}
	catch (Exception $e)
	{
		$values["error"] = 'Domain UnLock/EPP: '.$e->getMessage();
		return $values;
	}
	return $values;
}

# Function to register domain
function COCCAepp_RegisterDomain($params)
{
    
    // user defined configuration values
    # Grab varaibles
    $sld = $params["sld"];
    $tld = $params["tld"];
    $regperiod = $params["regperiod"];
    $domain = "$sld.$tld";
    # Get registrant details
    $RegistrantFirstName = htmlspecialchars ($params["firstname"], ENT_XML1, 'UTF-8');
    $RegistrantLastName = htmlspecialchars ($params["lastname"], ENT_XML1, 'UTF-8');
    $RegistrantOrganizationName = htmlspecialchars ($params["companyname"], ENT_XML1, 'UTF-8');
    $RegistrantAddress1 = htmlspecialchars ($params["address1"], ENT_XML1, 'UTF-8');
    $RegistrantAddress2 = htmlspecialchars ($params["address2"], ENT_XML1, 'UTF-8');
    $RegistrantCity = htmlspecialchars ($params["city"], ENT_XML1, 'UTF-8');
    $RegistrantStateProvince = htmlspecialchars ($params["state"], ENT_XML1, 'UTF-8');
    $RegistrantPostalCode = $params["postcode"];
    $RegistrantCountry = $params["country"];
    $RegistrantEmailAddress = $params["email"];
    $RegistrantPhone = $params["fullphonenumber"];

    switch('.' . $tld)
    {
        case '.ma':
        $RegistrantType= $params['additionalfields']['Type'];
        $RegistrantNID= $params['additionalfields']['NID'];
        $RegistrantTID = $params['additionalfields']['TID'];
    case '.ote.ma':
        $RegistrantType= $params['additionalfields']['Type'];
        $RegistrantNID= $params['additionalfields']['NID'];
        $RegistrantTID = $params['additionalfields']['TID'];
    }

    #Generate Handle
    $regHandle = generateHandle();
    # Get admin details
    $AdminFirstName = $params["adminfirstname"];
    $AdminLastName = $params["adminlastname"];
    $AdminAddress1 = $params["adminaddress1"];
    $AdminAddress2 = $params["adminaddress2"];
    $AdminCity = $params["admincity"];
    $AdminStateProvince = $params["adminstate"];
    $AdminPostalCode = $params["adminpostcode"];
    $AdminCountry = $params["admincountry"];
    $AdminEmailAddress = $params["adminemail"];
    $AdminPhone = $params["adminfullphonenumber"];

    #Generate Handle
    $admHandle = generateHandle();

    # Generate array of new nameservers
    $nameservers=array();
    
    if(!empty($params["ns1"]))
    {
            array_push($nameservers,$params["ns1"]);
    }
    if(!empty($params["ns2"]))
    {
            array_push($nameservers,$params["ns2"]);
    }
    if(!empty($params["ns3"]))
    {
            array_push($nameservers,$params["ns3"]);
    }
    if(!empty($params["ns4"]))
    {
            array_push($nameservers,$params["ns4"]);
    }
    if(!empty($params["ns5"]))
    {
            array_push($nameservers,$params["ns5"]);
    }

    # Get client instance
    try
    {
        $client = _COCCAepp_Client();

	for($i=0; $i < count($nameservers); $i++)
	{
            # Get list of nameservers for domain
            $result = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
                                            <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
                                                    <command>
                                                            <info>
                                                                    <host:info xmlns:host="urn:ietf:params:xml:ns:host-1.0">
                                                                            <host:name>'.$nameservers[$i].'</host:name>
                                                                    </host:info>
                                                            </info>
                                                            <clTRID>'.mt_rand().mt_rand().'</clTRID>
                                                    </command>
                                            </epp>');
            # Parse XML result
            $doc = new DOMDocument();
            $doc->preserveWhiteSpace = false;
            $doc->loadXML($result);
            
           /* logModuleCall('COCCAepp', 'GetNameservers', $xml, $result); */
           	HelperFunction::coccaepp_logModuleCall('COCCAepp','GetNameservers', $xml, $result);

            # Pull off status
            $coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
            $msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;
            
            # Check the result is ok
            if($coderes == '2303')
            {
                $request = $client->request($xml ='<?xml version="1.0" encoding="UTF-8" standalone="no"?>
                                                    <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
                                                            <command>
                                                                    <create>
                                                                            <host:create xmlns:host="urn:ietf:params:xml:ns:host-1.0">
                                                                                    <host:name>'.$nameservers[$i].'</host:name>
                                                                            </host:create>
                                                                    </create>
                                                                    <clTRID>'.mt_rand().mt_rand().'</clTRID>
                                                            </command>
                                                    </epp>');
                # Parse XML result
                $doc= new DOMDocument();
                $doc->loadXML($request);
                
            /*    logModuleCall('COCCAepp', 'SaveNameservers', $xml, $request); */
                	HelperFunction::coccaepp_logModuleCall('COCCAepp','SaveNameservers', $xml, $request);

                # Pull off status
                $coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
                $msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;
                
                # Check if result is ok
		if(!eppSuccess($coderes))
                {
                    $values["error"] = "Could not Create host($nameservers[$i]): Code ($coderes) $msg";
                    return $values;
                }
            }
        }
		
	// End create nameservers  /////////

        # Generate XML for nameservers
    if ($nameserver1 = $params["ns1"])
    {
        $add_hosts = '<domain:hostObj>'.$nameserver1.'</domain:hostObj>';
    }
    if ($nameserver2 = $params["ns2"])
    {
        $add_hosts .= '<domain:hostObj>'.$nameserver2.'</domain:hostObj>';
    }
    if ($nameserver3 = $params["ns3"])
    {
        $add_hosts .= '<domain:hostObj>'.$nameserver3.'</domain:hostObj>';
    }
    if ($nameserver4 = $params["ns4"])
    {
        $add_hosts .= '<domain:hostObj>'.$nameserver4.'</domain:hostObj>';
    }
    if ($nameserver5 = $params["ns5"])
    {
        $add_hosts .= '<domain:hostObj>'.$nameserver5.'</domain:hostObj>';
    }
		
    $eppKey = authKey();
    # Create Registrant
if (empty($RegistrantAddress2) and empty($RegistrantOrganizationName)) {
    $request = $client->request($xml ='<?xml version="1.0" encoding="UTF-8" standalone="no"?>
                                        <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
                                                <command>
                                                        <create>
                                                                <contact:create xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
                                                                        <contact:id>'.$regHandle.'</contact:id>
                                                                        <contact:postalInfo type="loc">
                                                                                <contact:name>'.$RegistrantFirstName.' '.$RegistrantLastName.'</contact:name>
                                                                                <contact:addr>
                                                                                                <contact:street>'.$RegistrantAddress1.'</contact:street>
                                                                                                <contact:city>'.$RegistrantCity.'</contact:city>
                                                                                                <contact:sp>'.$RegistrantStateProvince.'</contact:sp>
                                                                                                <contact:pc>'.$RegistrantPostalCode.'</contact:pc>
                                                                                                <contact:cc>'.$RegistrantCountry.'</contact:cc>
                                                                                </contact:addr>
                                                                        </contact:postalInfo>
                                                                        <contact:voice>'.$params["fullphonenumber"].'</contact:voice>
                                                                        <contact:email>'.$RegistrantEmailAddress.'</contact:email>
                                                                        <contact:authInfo>
                                                                                        <contact:pw>'.$eppKey.'</contact:pw>
                                                                        </contact:authInfo>
                                                                </contact:create>
                                                        </create>
                                                        <extension>
                                                                <snic:contactInfo xmlns:snic="urn:saudinic:params:xml:ns:saudinic-1.0">
                                                                        <snic:mobile>'.$params["fullphonenumber"].'</snic:mobile>
                                                                </snic:contactInfo>
                                                        </extension>
                                                        <clTRID>'.mt_rand().mt_rand().'</clTRID>
                                                </command>
                                        </epp>');



} else if (!empty($RegistrantAddress2) and empty($RegistrantOrganizationName)) {
    $request = $client->request($xml ='<?xml version="1.0" encoding="UTF-8" standalone="no"?>
                                        <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
                                                <command>
                                                        <create>
                                                                <contact:create xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
                                                                        <contact:id>'.$regHandle.'</contact:id>
                                                                        <contact:postalInfo type="loc">
                                                                                <contact:name>'.$RegistrantFirstName.' '.$RegistrantLastName.'</contact:name>
                                                                                <contact:addr>
                                                                                                <contact:street>'.$RegistrantAddress1.'</contact:street>
                                                                                                <contact:street>'.$RegistrantAddress2.'</contact:street>
                                                                                                <contact:city>'.$RegistrantCity.'</contact:city>
                                                                                                <contact:sp>'.$RegistrantStateProvince.'</contact:sp>
                                                                                                <contact:pc>'.$RegistrantPostalCode.'</contact:pc>
                                                                                                <contact:cc>'.$RegistrantCountry.'</contact:cc>
                                                                                </contact:addr>
                                                                        </contact:postalInfo>
                                                                        <contact:voice>'.$params["fullphonenumber"].'</contact:voice>
                                                                        <contact:email>'.$RegistrantEmailAddress.'</contact:email>
                                                                        <contact:authInfo>
                                                                                        <contact:pw>'.$eppKey.'</contact:pw>
                                                                        </contact:authInfo>
                                                                </contact:create>
                                                        </create>
                                                        <extension>
                                                                <snic:contactInfo xmlns:snic="urn:saudinic:params:xml:ns:saudinic-1.0">
                                                                        <snic:mobile>'.$params["fullphonenumber"].'</snic:mobile>
                                                                </snic:contactInfo>
                                                        </extension>
                                                        <clTRID>'.mt_rand().mt_rand().'</clTRID>
                                                </command>
                                        </epp>');

} else if (empty($RegistrantAddress2) and !empty($RegistrantOrganizationName) and $tld=='pub.sa') {
    $request = $client->request($xml ='<?xml version="1.0" encoding="UTF-8" standalone="no"?>
                                        <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
                                                <command>
                                                        <create>
                                                                <contact:create xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
                                                                        <contact:id>'.$regHandle.'</contact:id>
                                                                        <contact:postalInfo type="loc">
                                                                                <contact:name>'.$RegistrantFirstName.' '.$RegistrantLastName.'</contact:name>
                                                                                <contact:org>'.$RegistrantOrganizationName.'</contact:org>
                                                                                <contact:addr>
                                                                                                <contact:street>'.$RegistrantAddress1.'</contact:street>
                                                                                                <contact:city>'.$RegistrantCity.'</contact:city>
                                                                                                <contact:sp>'.$RegistrantStateProvince.'</contact:sp>
                                                                                                <contact:pc>'.$RegistrantPostalCode.'</contact:pc>
                                                                                                <contact:cc>'.$RegistrantCountry.'</contact:cc>
                                                                                </contact:addr>
                                                                        </contact:postalInfo>
                                                                        <contact:voice>'.$params["fullphonenumber"].'</contact:voice>
                                                                        <contact:email>'.$RegistrantEmailAddress.'</contact:email>
                                                                        <contact:authInfo>
                                                                                        <contact:pw>'.$eppKey.'</contact:pw>
                                                                        </contact:authInfo>
                                                                </contact:create>
                                                        </create>
                                                        <extension>
                                                                <snic:contactInfo xmlns:snic="urn:saudinic:params:xml:ns:saudinic-1.0">
                                                                        <snic:mobile>'.$params["fullphonenumber"].'</snic:mobile>
                                                                </snic:contactInfo>
                                                        </extension>
                                                        <clTRID>'.mt_rand().mt_rand().'</clTRID>
                                                </command>
                                        </epp>');
} else if (empty($RegistrantAddress2) and !empty($RegistrantOrganizationName) and $tld!='pub.sa') {
    $request = $client->request($xml ='<?xml version="1.0" encoding="UTF-8" standalone="no"?>
                                        <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
                                                <command>
                                                        <create>
                                                                <contact:create xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
                                                                        <contact:id>'.$regHandle.'</contact:id>
                                                                        <contact:postalInfo type="loc">
                                                                                <contact:name>'.$RegistrantOrganizationName.'</contact:name>
                                                                                <contact:org>'.$RegistrantOrganizationName.'</contact:org>
                                                                                <contact:addr>
                                                                                                <contact:street>'.$RegistrantAddress1.'</contact:street>
                                                                                                <contact:city>'.$RegistrantCity.'</contact:city>
                                                                                                <contact:sp>'.$RegistrantStateProvince.'</contact:sp>
                                                                                                <contact:pc>'.$RegistrantPostalCode.'</contact:pc>
                                                                                                <contact:cc>'.$RegistrantCountry.'</contact:cc>
                                                                                </contact:addr>
                                                                        </contact:postalInfo>
                                                                        <contact:voice>'.$params["fullphonenumber"].'</contact:voice>
                                                                        <contact:email>'.$RegistrantEmailAddress.'</contact:email>
                                                                        <contact:authInfo>
                                                                                        <contact:pw>'.$eppKey.'</contact:pw>
                                                                        </contact:authInfo>
                                                                </contact:create>
                                                        </create>
                                                        <extension>
                                                                <snic:contactInfo xmlns:snic="urn:saudinic:params:xml:ns:saudinic-1.0">
                                                                        <snic:mobile>'.$params["fullphonenumber"].'</snic:mobile>
                                                                </snic:contactInfo>
                                                        </extension>
                                                        <clTRID>'.mt_rand().mt_rand().'</clTRID>
                                                </command>
                                        </epp>');
} else if (!empty($RegistrantAddress2) and !empty($RegistrantOrganizationName) and $tld=='pub.sa') {
    $request = $client->request($xml ='<?xml version="1.0" encoding="UTF-8" standalone="no"?>
                                        <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
                                                <command>
                                                        <create>
                                                                <contact:create xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
                                                                        <contact:id>'.$regHandle.'</contact:id>
                                                                        <contact:postalInfo type="loc">
                                                                                <contact:name>'.$RegistrantFirstName.' '.$RegistrantLastName.'</contact:name>
                                                                                <contact:org>'.$RegistrantOrganizationName.'</contact:org>
                                                                                <contact:addr>
                                                                                                <contact:street>'.$RegistrantAddress1.'</contact:street>
                                                                                                <contact:street>'.$RegistrantAddress2.'</contact:street>
                                                                                                <contact:city>'.$RegistrantCity.'</contact:city>
                                                                                                <contact:sp>'.$RegistrantStateProvince.'</contact:sp>
                                                                                                <contact:pc>'.$RegistrantPostalCode.'</contact:pc>
                                                                                                <contact:cc>'.$RegistrantCountry.'</contact:cc>
                                                                                </contact:addr>
                                                                        </contact:postalInfo>
                                                                        <contact:voice>'.$params["fullphonenumber"].'</contact:voice>
                                                                        <contact:email>'.$RegistrantEmailAddress.'</contact:email>
                                                                        <contact:authInfo>
                                                                                        <contact:pw>'.$eppKey.'</contact:pw>
                                                                        </contact:authInfo>
                                                                </contact:create>
                                                        </create>
                                                        <extension>
                                                                <snic:contactInfo xmlns:snic="urn:saudinic:params:xml:ns:saudinic-1.0">
                                                                        <snic:mobile>'.$params["fullphonenumber"].'</snic:mobile>
                                                                </snic:contactInfo>
                                                        </extension>
                                                        <clTRID>'.mt_rand().mt_rand().'</clTRID>
                                                </command>
                                        </epp>');
} else if (!empty($RegistrantAddress2) and !empty($RegistrantOrganizationName) and $tld!='pub.sa') {
    $request = $client->request($xml ='<?xml version="1.0" encoding="UTF-8" standalone="no"?>
                                        <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
                                                <command>
                                                        <create>
                                                                <contact:create xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
                                                                        <contact:id>'.$regHandle.'</contact:id>
                                                                        <contact:postalInfo type="loc">
                                                                                <contact:name>'.$RegistrantOrganizationName.'</contact:name>
                                                                                <contact:org>'.$RegistrantOrganizationName.'</contact:org>
                                                                                <contact:addr>
                                                                                                <contact:street>'.$RegistrantAddress1.'</contact:street>
                                                                                                <contact:street>'.$RegistrantAddress2.'</contact:street>
                                                                                                <contact:city>'.$RegistrantCity.'</contact:city>
                                                                                                <contact:sp>'.$RegistrantStateProvince.'</contact:sp>
                                                                                                <contact:pc>'.$RegistrantPostalCode.'</contact:pc>
                                                                                                <contact:cc>'.$RegistrantCountry.'</contact:cc>
                                                                                </contact:addr>
                                                                        </contact:postalInfo>
                                                                        <contact:voice>'.$params["fullphonenumber"].'</contact:voice>
                                                                        <contact:email>'.$RegistrantEmailAddress.'</contact:email>
                                                                        <contact:authInfo>
                                                                                        <contact:pw>'.$eppKey.'</contact:pw>
                                                                        </contact:authInfo>
                                                                </contact:create>
                                                        </create>
                                                        <extension>
                                                                <snic:contactInfo xmlns:snic="urn:saudinic:params:xml:ns:saudinic-1.0">
                                                                        <snic:mobile>'.$params["fullphonenumber"].'</snic:mobile>
                                                                </snic:contactInfo>
                                                        </extension>
                                                        <clTRID>'.mt_rand().mt_rand().'</clTRID>
                                                </command>
                                        </epp>');
}


        # Parse XML result
        $doc= new DOMDocument();
        $doc->loadXML($request);
        
       /* logModuleCall('COCCAepp', 'ContactRegistrantCreate', $xml, $request); */
        	HelperFunction::coccaepp_logModuleCall('COCCAepp','ContactRegistrantCreate', $xml, $request);
        
        # Pull off status
        $coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
        
        $msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;
        
        if(eppSuccess($coderes))
        {
            $values['contact'] = 'Contact Created';
        }
        else if($coderes == '2302')
        {
            $values['contact'] = 'Contact Already exists';
        }
        else
        {
            $values["error"] = "RegisterDomain/Reg-create($regHandle): Code ($coderes) $msg";
            return $values;
        }

        $values["status"] = $msg;
        $eppKey =  authKey();
        //Create Domain Admin
	// read mysql values from admin_contact_verify table
        $result = mysql_query("SELECT * FROM admin_contact_verify WHERE domain_name = '" . $domain . "' AND used='0' ORDER BY id DESC LIMIT 1");

        $row = mysql_fetch_assoc($result);

if (empty($row["contact_street_1"]) and empty($row["contact_fax"])) {
	        $request = $client->request($xml ='<?xml version="1.0" encoding="UTF-8" standalone="no"?>
   <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
     <command>
       <create>
         <contact:create xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
                                <contact:id>'.$admHandle.'</contact:id>
                                <contact:postalInfo type="loc">
                                        <contact:name>'.htmlspecialchars($row["contact_name"]).'</contact:name>
                                        <contact:org>'.htmlspecialchars($row["contact_org"]).'</contact:org>
                                        <contact:addr>
                                                <contact:street>'.htmlspecialchars($row["contact_street"]).'</contact:street>
                                                <contact:city>'.htmlspecialchars($row["contact_city"]).'</contact:city>
                                                <contact:sp>'.htmlspecialchars($row["contact_sp"]).'</contact:sp>
                                                <contact:pc>'.$row["contact_pc"].'</contact:pc>
                                                <contact:cc>'.$row["contact_cc"].'</contact:cc>
                                        </contact:addr>
                                </contact:postalInfo>
                                <contact:voice x="'.$row["contact_voice_x"].'">'.$row["contact_voice"].'</contact:voice>
                                <contact:email>'.$row["contact_email"].'</contact:email>
                                <contact:authInfo>
                                        <contact:pw>'.$eppKey.'</contact:pw>
                                </contact:authInfo>
                        </contact:create>
                </create>
                <extension>
                          <snic:contactInfo xmlns:snic="urn:saudinic:params:xml:ns:saudinic-1.0">
                                <snic:website>'.$row["snic_website"].'</snic:website>
                                <snic:mobile>'.$row["snic_mobile"].'</snic:mobile>
                                <snic:position>'.htmlspecialchars($row["snic_position"]).'</snic:position>
                        </snic:contactInfo>
                </extension>
                <clTRID>'.mt_rand().mt_rand().'</clTRID>
        </command>
</epp>
');

} else if (!empty($row["contact_street_1"]) and empty($row["contact_fax"])) {
        $request = $client->request($xml ='<?xml version="1.0" encoding="UTF-8" standalone="no"?>
   <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
     <command>
       <create>
         <contact:create xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
                                <contact:id>'.$admHandle.'</contact:id>
                                <contact:postalInfo type="loc">
                                        <contact:name>'.htmlspecialchars($row["contact_name"]).'</contact:name>
                                        <contact:org>'.htmlspecialchars($row["contact_org"]).'</contact:org>
                                        <contact:addr>
                                                <contact:street>'.htmlspecialchars($row["contact_street"]).'</contact:street>
                                                <contact:street>'.htmlspecialchars($row["contact_street_1"]).'</contact:street>
                                                <contact:city>'.htmlspecialchars($row["contact_city"]).'</contact:city>
                                                <contact:sp>'.htmlspecialchars($row["contact_sp"]).'</contact:sp>
                                                <contact:pc>'.$row["contact_pc"].'</contact:pc>
                                                <contact:cc>'.$row["contact_cc"].'</contact:cc>
                                        </contact:addr>
                                </contact:postalInfo>
                                <contact:voice x="'.$row["contact_voice_x"].'">'.$row["contact_voice"].'</contact:voice>
                                <contact:email>'.$row["contact_email"].'</contact:email>
                                <contact:authInfo>
                                        <contact:pw>'.$eppKey.'</contact:pw>
                                </contact:authInfo>
                        </contact:create>
                </create>
                <extension>
                          <snic:contactInfo xmlns:snic="urn:saudinic:params:xml:ns:saudinic-1.0">
                                <snic:website>'.$row["snic_website"].'</snic:website>
                                <snic:mobile>'.$row["snic_mobile"].'</snic:mobile>
                                <snic:position>'.htmlspecialchars($row["snic_position"]).'</snic:position>
                        </snic:contactInfo>
                </extension>
                <clTRID>'.mt_rand().mt_rand().'</clTRID>
        </command>
</epp>
');
} else if (empty($row["contact_street_1"]) and !empty($row["contact_fax"])) {
        $request = $client->request($xml ='<?xml version="1.0" encoding="UTF-8" standalone="no"?>
   <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
     <command>
       <create>
         <contact:create xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
                                <contact:id>'.$admHandle.'</contact:id>
                                <contact:postalInfo type="loc">
                                        <contact:name>'.htmlspecialchars($row["contact_name"]).'</contact:name>
                                        <contact:org>'.htmlspecialchars($row["contact_org"]).'</contact:org>
                                        <contact:addr>
                                                <contact:street>'.htmlspecialchars($row["contact_street"]).'</contact:street>
                                                <contact:city>'.htmlspecialchars($row["contact_city"]).'</contact:city>
                                                <contact:sp>'.htmlspecialchars($row["contact_sp"]).'</contact:sp>
                                                <contact:pc>'.$row["contact_pc"].'</contact:pc>
                                                <contact:cc>'.$row["contact_cc"].'</contact:cc>
                                        </contact:addr>
                                </contact:postalInfo>
                                <contact:voice x="'.$row["contact_voice_x"].'">'.$row["contact_voice"].'</contact:voice>
                                <contact:fax>'.$row["contact_fax"].'</contact:fax>
                                <contact:email>'.$row["contact_email"].'</contact:email>
                                <contact:authInfo>
                                        <contact:pw>'.$eppKey.'</contact:pw>
                                </contact:authInfo>
                        </contact:create>
                </create>
                <extension>
                          <snic:contactInfo xmlns:snic="urn:saudinic:params:xml:ns:saudinic-1.0">
                                <snic:website>'.$row["snic_website"].'</snic:website>
                                <snic:mobile>'.$row["snic_mobile"].'</snic:mobile>
                                <snic:position>'.htmlspecialchars($row["snic_position"]).'</snic:position>
                        </snic:contactInfo>
                </extension>
                <clTRID>'.mt_rand().mt_rand().'</clTRID>
        </command>
</epp>
');
} else if (!empty($row["contact_street_1"]) and !empty($row["contact_fax"])) {
        $request = $client->request($xml ='<?xml version="1.0" encoding="UTF-8" standalone="no"?>
   <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
     <command>
       <create>
         <contact:create xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
                                <contact:id>'.$admHandle.'</contact:id>
                                <contact:postalInfo type="loc">
                                        <contact:name>'.htmlspecialchars($row["contact_name"]).'</contact:name>
                                        <contact:org>'.htmlspecialchars($row["contact_org"]).'</contact:org>
                                        <contact:addr>
                                                <contact:street>'.htmlspecialchars($row["contact_street"]).'</contact:street>
                                                <contact:street>'.htmlspecialchars($row["contact_street_1"]).'</contact:street>
                                                <contact:city>'.htmlspecialchars($row["contact_city"]).'</contact:city>
                                                <contact:sp>'.htmlspecialchars($row["contact_sp"]).'</contact:sp>
                                                <contact:pc>'.$row["contact_pc"].'</contact:pc>
                                                <contact:cc>'.$row["contact_cc"].'</contact:cc>
                                        </contact:addr>
                                </contact:postalInfo>
                                <contact:voice x="'.$row["contact_voice_x"].'">'.$row["contact_voice"].'</contact:voice>
                                <contact:fax>'.$row["contact_fax"].'</contact:fax>
                                <contact:email>'.$row["contact_email"].'</contact:email>
                                <contact:authInfo>
                                        <contact:pw>'.$eppKey.'</contact:pw>
                                </contact:authInfo>
                        </contact:create>
                </create>
                <extension>
                          <snic:contactInfo xmlns:snic="urn:saudinic:params:xml:ns:saudinic-1.0">
                                <snic:website>'.$row["snic_website"].'</snic:website>
                                <snic:mobile>'.$row["snic_mobile"].'</snic:mobile>
                                <snic:position>'.htmlspecialchars($row["snic_position"]).'</snic:position>
                        </snic:contactInfo>
                </extension>
                <clTRID>'.mt_rand().mt_rand().'</clTRID>
        </command>
</epp>
');
}

        # Parse XML result
        $doc = new DOMDocument();
        $doc->loadXML($request);
        
      /*  logModuleCall('COCCAepp', 'AdminContactCreate', $xml, $request); */
        HelperFunction::coccaepp_logModuleCall('COCCAepp','AdminContactCreate', $xml, $request);
        
        # Pull off status
        $coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
        $msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;
        
        if(eppSuccess($coderes))
        {
            $values['contact'] = 'Contact Created';
        }
        else if($coderes == '2302')
        {
            $values['contact'] = 'Contact Already exists';
        }
        else
        {
            $values["error"] = "RegisterDomain/Admin Contact-create($admHandle): Code ($coderes) $msg";
            return $values;
        }
        
        $eppKey =  authKey();
        $values["status"] = $msg;
		   
        $extension = ''; 
           
	$coccatld = array("sa", "com.sa", "net.sa", "edu.sa", "med.sa", "org.sa", "sch.sa");
	// $coccatld = array("pub.sa", "edu.sa", "med.sa", "org.sa", "sch.sa");
  
      	if(in_array($params["tld"], $coccatld))
      	{
            $getFiles = DB::table('mod_kwupload')->where("domain",$params["domain"])->get();
			
            $filesData = $extension = $documentData = ''; 
			
            foreach($getFiles as $key => $val)
            { 
                if($val->sent == 0)
                {
                    
                    $documentData.= '<snic:document>
                                        <snic:documentType>'.$val->document_type.'</snic:documentType>
                                        <snic:documentTypeName>'.$val->document_name.'</snic:documentTypeName>
                                        <snic:issuer>'.$val->issuer.'</snic:issuer>
                                        <snic:issuerName>'.$val->issuer_name.'</snic:issuerName>
                                        <snic:documentId>'.$val->document_id.'</snic:documentId> 
                                    </snic:document>';
                    
                    $filesData.= '<snic:file>
                                    <snic:fileName>'.$val->document_type.'</snic:fileName>
                                    <snic:fileExt>'.$val->fileext.'</snic:fileExt>
                                    <snic:data>'.$val->data.'</snic:data>
                                </snic:file>';
                     
                    
                    /*
                    	$documentData.= '<snic:document>';
                    
                        if(!empty($val->document_type))
                        {
                            $documentData.= '<snic:documentType>'.$val->document_type.'</snic:documentType>';
                        }
                        else
                        {
                            $documentData.= '';//'</snic:documentType>';
                        }


                        if(!empty($val->document_name))
                        {
                            $documentData.= '<snic:documentTypeName>'.$val->document_name.'</snic:documentTypeName>';
                        }
                        else
                        {
                            $documentData.= '';//'</snic:documentTypeName>';
                        }


                        if(!empty($val->issuer))
                        {
                            $documentData.= '<snic:issuer>'.$val->issuer.'</snic:issuer>';
                        }
                        else
                        {
                            $documentData.= '';//'<snic:issuer />';
                        }

                        if(!empty($val->issuer_name))
                        {
                            $documentData.= '<snic:issuerName>'.$val->issuer_name.'</snic:issuerName>';
                        }
                        else
                        {
                            $documentData.= '';//'<snic:issuerName />';
                        }


                        if(!empty($val->document_id))
                        {
                            $documentData.= '<snic:documentId>'.$val->document_id.'</snic:documentId>';
                        }
                        else
                        {
                            $documentData.= '';//'<snic:documentId />';
                        }
                    
                    $documentData.= '</snic:document>';
                                        
                                        
                                        
                    $filesData.= '<snic:file>';
                    
                        if(!empty($val->document_type))
                        {
                            $filesData.= '<snic:fileName>'.$val->document_type.'</snic:fileName>';
                        }
                        else
                        {
                            $filesData.= '';//'<snic:fileName />';
                        }

                        if(!empty($val->fileext))
                        {
                            $filesData.= '<snic:fileExt>'.$val->fileext.'</snic:fileExt>';
                        }
                        else
                        {
                            $filesData.= '';//'<snic:fileExt>';
                        }

                        if(!empty($val->data))
                        {
                            $filesData.= '<snic:data>'.$val->data.'</snic:data>';
                        }
                        else
                        {
                            $filesData.= '';//'</snic:data>';
                        }
                    
						$filesData.= '</snic:file>';
						*/
                }
            }  
           
            
            if($documentData && $filesData)
            {
                    $extension = '<extension>
                    <snic:supportingDocs xmlns:snic="urn:saudinic:params:xml:ns:saudinic-1.0">
                    '.$documentData.$filesData.'
                    </snic:supportingDocs>
                    </extension>';
            }
            
	}
 
        /*end upload files data*/
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
                                <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
                                        <command>
                                                <create>
                                                        <domain:create xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
                                                                <domain:name>'.$sld.'.'.$tld.'</domain:name>
                                                                <domain:period unit="y">'.$regperiod.'</domain:period>
                                                                <domain:ns>'.$add_hosts.'</domain:ns>
                                                                <domain:registrant>'.$regHandle.'</domain:registrant>
                                                                <domain:contact type="admin">'.$admHandle.'</domain:contact>
                                                                <domain:contact type="tech">'.$admHandle.'</domain:contact>
                                                                <domain:contact type="billing">'.$admHandle.'</domain:contact>
                                                                <domain:authInfo>
                                                                        <domain:pw>'.$eppKey.'</domain:pw>
                                                                </domain:authInfo>
                                                        </domain:create>
                                                </create>'.$extension.'		
                                                <clTRID>'.mt_rand().mt_rand().'</clTRID>
                                        </command>
                                </epp>'; 

            $request = $client->request($xml);

            $doc= new DOMDocument();
            $doc->loadXML($request);

           /* logModuleCall('COCCAepp', 'RegisterDomain', $xml, $request); */
             HelperFunction::coccaepp_logModuleCall('COCCAepp','RegisterDomain', $xml, $request);
             

            $coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
            $msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;

            if(!eppSuccess($coderes))
            {
                $values["error"] = "RegisterDomain/domain-create($sld.$tld): Code ($coderes) $msg";
                return $values;
            }

            DB::table('mod_kwupload')->where("domain",$params["domain"])->update(['sent' => 1]);
            DB::table('admin_contact_verify')->where("domain_name",$params["domain"])->update(['used' => 1]);


            $values["status"] = $msg;

            return $values;

	}
	catch (Exception $e)
	{
              /*  logModuleCall('COCCAepp', 'RegisterDomain', $params, $e->getMessage()); */
                HelperFunction::coccaepp_logModuleCall('COCCAepp','RegisterDomain', $params, $e->getMessage());
		$values["error"] = 'RegisterDomain/EPP: '.$e->getMessage();
		return $values;
	}

	return $values;

}

# Function to transfer a domain
function COCCAepp_TransferDomain($params)
{
	global $_LANG;
    # Grab variables
    $testmode = $params["TestMode"];
    $sld = $params["sld"];
    $tld = $params["tld"];
    $domain = "$sld.$tld";
    # Domain info
    $regperiod = $params["regperiod"];
    $transfersecret = $params["transfersecret"];
    $nameserver1 = $params["ns1"];
    $nameserver2 = $params["ns2"];
    # Registrant Details
    $RegistrantFirstName = $params["firstname"];
    $RegistrantLastName = $params["lastname"];
    $RegistrantAddress1 = $params["address1"];
    $RegistrantAddress2 = $params["address2"];
    $RegistrantCity = $params["city"];
    $RegistrantStateProvince = $params["state"];
    $RegistrantPostalCode = $params["postcode"];
    $RegistrantCountry = $params["country"];
    $RegistrantEmailAddress = $params["email"];
    $RegistrantPhone = $params["fullphonenumber"];
 

	$where = [
				['domainid', '=', $params["domainid"]],
				['userid', '=', $params["userid"]]
			];

    $getresult = DB::table('cocca_save_domains')->where($where)->first();
	 
    if(empty($getresult->id))
    { 
		global $CONFIG;

		/* To check/create cocca_save_domains custom table */
        coccaepp_schema();

        #Generate Random otp + authkey
      	$otp = (string)mt_rand(111111, 999999);
	    $encryptauthkey = substr(md5(time()), 0, 16);

	    //Insert Custom data
	    $insert = array(
            'domainid' => $params["domainid"],
            'userid'=> $params["userid"],
            'auth_key'=> $encryptauthkey,
            'otp'=> $otp,
            'type'=>'transfer'
        );

	    #Email send
        $msg = $_LANG['dear_administrator'].",<br><br><br>".
		$_LANG['for_your_domain_transfer_request_email_statement'] ."<br><br><br>";
		$msg .=  $_LANG['your_domain_is']." : ".$domain."<br><br>";
		$msg .= "<a href='".$CONFIG['SystemURL']."/updatedomainrequest.php?domainid=".$params['domainid']."&type=transfer&userid=".$params['userid']."&authkey=".urlencode($encryptauthkey)."' target='_blank'><button type='button'>".$_LANG['click_to_proceed']."</button></a></br>";

        
        $getConatct =   COCCAepp_GetContactDetails($params);
	    $adminEmail =  $getConatct['Admin']['Email'];
        
        if(empty($adminEmail))
        {
            $adminEmail =  $RegistrantEmailAddress;
        }

        $subject = $_LANG['domain_transfer_record_request']." : ".$domain;

        include_once dirname(__FILE__) . '/mail.php';

        $status = emailtouser($adminEmail, $subject, $msg);

        if($status)
        {
            coccaepp_insertData('cocca_save_domains', $insert, $params["userid"]);

            logActivity("***-- Domain transfer approval email has been sent to admin.Domain ID: {$params['domainid']} User ID: {$params['userid']} --***");
            HelperFunction::coccaepp_approval_logModuleCall($params['domainname'], 'Send Email: Domain transfer approval', 'Domain transfer','Email Sent Success');
            
        }
        else
        {
            logActivity("***-- Domain transfer approval email has been failed.Domain ID: {$params['domainid']} User ID: {$params['userid']} --***");
            HelperFunction::coccaepp_approval_logModuleCall($params['domainname'], 'Send Email failed: Domain transfer approval', 'Domain transfer','Email Sent failed');
		}
		//send OTP on Phone number
		include_once dirname(__FILE__) . '/send_sms.php';
		$countrycode=explode('.',$getConatct['Admin']['Mobile'])[0];
		$number=explode('.',$getConatct['Admin']['Mobile'])[1];
		$send = sendsms($countrycode, $number, $domain, $otp);
		if($send)
		{
			logActivity("***--Domain transfer approval OTP has been sent successfully.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
			 HelperFunction::coccaepp_approval_logModuleCall($params['domainname'], 'Send SMS: Domain transfer approval', 'Domain transfer','SMS Sent Success');
		}
		else
		{
			logActivity("***--Domain transfer approval OTP send has been failed.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
			 HelperFunction::coccaepp_approval_logModuleCall($params['domainname'], 'Send SMS failed: Domain transfer approval', 'Domain transfer','SMS Sent failed');
		}

        $values["error"] = 'Domain transfer approval request sent.';

        return $values;
	}
	else if($getresult->status == 0)
	{
		$values["error"] = 'Domain '.$getresult->type.' approval request is pending.'; 
        return $values;
	}

    # Get client instance
    try
    {
        $client = _COCCAepp_Client();

        # Initiate transfer
        $request = $client->request($xml ='<?xml version="1.0" encoding="UTF-8" standalone="no"?>
												<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
													<command>
														<transfer op="request">
															<domain:transfer xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
																<domain:name>'.$sld.'.'.$tld.'</domain:name>
																<domain:period unit="y">1</domain:period>
																<domain:authInfo><domain:pw>'.$transfersecret.'</domain:pw></domain:authInfo>
															</domain:transfer>
														</transfer>
														<clTRID>'.mt_rand().mt_rand().'</clTRID>
													</command>
												</epp>');

        # Parse XML result
        $doc= new DOMDocument();
        $doc->loadXML($request);
      /*  logModuleCall('COCCAepp', 'TransferDomain', $xml, $request); */
         HelperFunction::coccaepp_logModuleCall('COCCAepp','TransferDomain', $xml, $request);

        $coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
        $msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;

        if(!eppSuccess($coderes))
        {
            $values["error"] = "TransferDomain/domain-transfer($sld.$tld): Code ($coderes) $msg";
            return $values;
        }

        $values["status"] = $msg;

    }
    catch (Exception $e)
    { 
        $values["error"] = 'TransferDomain/EPP: '.$e->getMessage();
        return $values;
    }

    $values["status"] = $msg;

    return $values;
}

# Function to renew domain
function COCCAepp_RenewDomain($params)
{
	global $_LANG;
    # Grab variables
    $sld = $params["sld"];
    $tld = $params["tld"];
    $regperiod = $params["regperiod"];
    $domain = "$sld.$tld";
    # Get client instance

	$where = [
				['domainid', '=', $params["domainid"]],
				['userid', '=', $params["userid"]]
			];

	$getresult = DB::table('cocca_save_domains')->where($where)->first();

	if(empty($getresult->id))
	{ 
        global $CONFIG;
		global $_LANG;

		/* To check/create cocca_save_domains custom table */
		coccaepp_schema();

        #Generate Random otp + authkey
        $otp = (string)mt_rand(111111, 999999);
		$encryptauthkey = substr(md5(time()), 0, 16);

		//Insert Custom data
		$insert = array(
                        'domainid' => $params["domainid"],
                        'userid'=> $params["userid"],
						'auth_key'=> $encryptauthkey,
						'period'=> $params["regperiod"],
                        'otp'=> $otp,
                        'type'=>'renew',
                        'created_at'=>date('Y-m-d h:i:s')
            );

		#Email send
        $msg = $_LANG['dear_administrator'].",<br><br>".
        $_LANG['for_your_domain_renew_request_email_statementnew']."<br><br>".
		$_LANG['for_your_domain_renew_request_email_statementnew3']."<br><br>";
		$msg .= "<a href='".$CONFIG['SystemURL']."/updatedomainrequest.php?domainid=".$params['domainid']."&type=renew&userid=".$params['userid']."&authkey=".urlencode($encryptauthkey)."' target='_blank'><button type='button'>".$_LANG['click_to_proceed']."</button></a><br><br><br>";
        $msg .= $_LANG['for_your_domain_renew_request_email_statementnew2'].",<br><br>";
 

        $getConatct =   COCCAepp_GetContactDetails($params);
		$adminEmail =  $getConatct['Admin']['Email'];

        $subject = $_LANG['domain_renew_request']." : ".$domain;

        include_once dirname(__FILE__) . '/mail.php';

        $status = false;

        if(!empty($adminEmail))
        {
            $status = emailtouser($adminEmail, $subject, $msg);
        }

        if($status)
        {
            coccaepp_insertData('cocca_save_domains', $insert, $params["userid"]);

            logActivity("***-- Domain renew approval email has been sent to admin.Domain ID: {$params['domainid']} User ID: {$params['userid']} --***");
            HelperFunction::coccaepp_approval_logModuleCall($params['domainname'], 'Send Email: Domain renew approval', 'Domain renew','Email Sent Success');
        }
        else
        {
            logActivity("***-- Domain renew approval email has been failed.Domain ID: {$params['domainid']} User ID: {$params['userid']} --***");
              HelperFunction::coccaepp_approval_logModuleCall($params['domainname'], 'Send Email failed: Domain renew approval', 'Domain renew','Email Sent failed');
		}
		//send OTP on Phone number
		include_once dirname(__FILE__) . '/send_sms.php';
		$countrycode=explode('.',$getConatct['Admin']['Mobile'])[0];
		$number=explode('.',$getConatct['Admin']['Mobile'])[1];
		$send = sendsms($countrycode, $number, $domain, $otp);
		if($send)
		{
			logActivity("***--Domain renew approval OTP has been sent successfully.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
			HelperFunction::coccaepp_approval_logModuleCall($params['domainname'], 'Send SMS: Domain renew approval', 'Domain renew','SMS Sent Success');
		}
		else
		{
			logActivity("***--Domain renew approval send OTP has been failed.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
			HelperFunction::coccaepp_approval_logModuleCall($params['domainname'], 'Send SMS failed: Domain renew approval', 'Domain renew','SMS Sent Failed');
		}

        $values["error"] = 'Domain renew approval request is sent.';

        return $values;
	} 
	else if($getresult->status == 0)
	{
		$values["error"] = 'Domain '.$getresult->type.' approval request is pending.'; 
        return $values;
	}

    try
    {  
        $client = _COCCAepp_Client();

        # Send renewal request
        $request = $client->request($xml='<?xml version="1.0" encoding="UTF-8" standalone="no"?>
												<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
													<command>
														<info>
															<domain:info xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
																<domain:name>'.$sld.'.'.$tld.'</domain:name>
															</domain:info>
														</info>
														<clTRID>'.mt_rand().mt_rand().'</clTRID>
													</command>
                                            	</epp>');

        # Parse XML result
        $doc= new DOMDocument();
        $doc->loadXML($request);
       /* logModuleCall('COCCAepp', 'RenewDomain', $xml, $request); */
          HelperFunction::coccaepp_logModuleCall('COCCAepp','RenewDomain', $xml, $request);

        $coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
        $msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;

        if(!eppSuccess($coderes))
        {
            $values["error"] = "RenewDomain/domain-info($sld.$tld)): Code ($coderes) $msg";
            return $values;
        }

        //check domain status

        if ($doc->getElementsByTagName('status')->item(0))
        {
            $statusres = $doc->getElementsByTagName('status')->item(0)->getAttribute('s');
            $requestedby = $doc->getElementsByTagName('status')->item(0)->nodeValue;
            $createdate = substr($doc->getElementsByTagName('crDate')->item(0)->nodeValue,0,10);
            $nextduedate = substr($doc->getElementsByTagName('exDate')->item(0)->nodeValue,0,10);
            $deldate= $doc->getElementsByTagName('upDate')->item(0)->nodeValue;
            $currdate1 = date("Y-m-d");
            $currdate2 = date("H:i:s");
        }
        else
        {
            $values['error'] = "RenewDomain/domain-info($domain): Domain not found";
            return $values;
        }

        $values['status'] = $msg;

        #Sanitize expiry date
        $expdate = substr($doc->getElementsByTagName('exDate')->item(0)->nodeValue,0,10);

        if(empty($expdate))
        {
            $values["error"] = "RenewDomain/domain-info($sld.$tld): Domain info not available";
            return $values;
        }

        # Check if domain in pendingdelete state then send a restore command with resotore report

        if($statusres == "pendingDelete")
        {
            // $values['error'] = "RenewDomain/domain-info($domain): Domain Status :pendingDelete";
            // here will send restore command
            $restore_request = $client->request($xml='<?xml version="1.0" encoding="UTF-8" standalone="no"?>
       <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
            <command xmlns="urn:ietf:params:xml:ns:epp-1.0">
                                                       <update>
                                                               <domain:update xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
                                                               <domain:name>'.$sld.'.'.$tld.'</domain:name>
                                                               <domain:chg/>
                                                             </domain:update>
                                                       </update>
                                                       <extension>
                                                             <rgp:update xmlns:rgp="urn:ietf:params:xml:ns:rgp-1.0">
                                                               <rgp:restore op="request"/>
                                                             </rgp:update>
                                                       </extension>
    <clTRID>'.mt_rand().mt_rand().'</clTRID>
    </command>
    </epp>');
            # Parse XML restore result
            $doc= new DOMDocument();
            $doc->loadXML($request);
           /* logModuleCall('COCCAepp', 'RestoreDomain', $xml, $request); */
             HelperFunction::coccaepp_logModuleCall('COCCAepp','RestoreDomain', $xml, $request);
             
            $coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
            $msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;

            if(!eppSuccess($coderes))
            {
                $values["error"] = "RenewDomain/Restore domain-info($sld.$tld)): Code ($coderes) $msg";
                return $values;
            }
        }
		
		/*  --------  */
        $values['status'] = $msg;
        # Send request to renew
         if($statusres != "pendingDelete")
         {
        $request = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
												<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
													<command>
														<renew>
															<domain:renew xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
																<domain:name>'.$sld.'.'.$tld.'</domain:name>
																<domain:curExpDate>'.$expdate.'</domain:curExpDate>
																<domain:period unit="y">'.$regperiod.'</domain:period>
															</domain:renew>
														</renew>
														<clTRID>'.mt_rand().mt_rand().'</clTRID>
													</command>
												</epp>');
        # Parse XML result
        $doc= new DOMDocument();
        $doc->loadXML($request);
      /*  logModuleCall('COCCAepp', 'RenewDomain', $xml, $request); */
         HelperFunction::coccaepp_logModuleCall('COCCAepp','RenewDomain', $xml, $request);
         
        $coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
        $msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;

        if(!eppSuccess($coderes))
        {
            $values["error"] = "RenewDomain/domain-renew($sld.$tld,$expdate): Code (".$coderes.") ".$msg;
            return $values;
        }

        $values["status"] = $msg;
        }
    }
    catch (Exception $e)
    {
        $values["error"] = 'RenewDomain/EPP: '.$e->getMessage();
        return $values;
    }

    # If error, return the error message in the value below
    return $values;

}

# Function to grab contact details
function COCCAepp_GetContactDetails($params) 
{
    coccaepp_schema();
    
    # Grab variables
    $sld = $params["sld"];
    $tld = $params["tld"];
    
    $domain = "$sld.$tld";
    # Get client instance
    
    try
    {
        if (!isset($client)) 
        {
            $client = _COCCAepp_Client();
        }

		# Grab domain info 

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
					<epp xmlns="urn:ietf:params:xml:ns:epp-1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
						<command>
							<info>
								<domain:info xmlns:domain="urn:ietf:params:xml:ns:domain-1.0" xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
								<domain:name>'.$sld.'.'.$tld.'</domain:name>';
									if(!empty($params['eppcode']))
									{
										$xml .= '<domain:authInfo><domain:pw>'.$params['eppcode'].'</domain:pw></domain:authInfo>';
									}
			
								$xml .= '</domain:info>
							</info>
							<clTRID>'.mt_rand().mt_rand().'</clTRID>
						</command>
					</epp>';

        $result = $client->request($xml);

        # Parse XML result
        $doc= new DOMDocument();
        $doc->loadXML($result);
      /*  logModuleCall('COCCAepp', 'Get Contact Details', $xml, $result); */
         HelperFunction::coccaepp_logModuleCall('COCCAepp','Get Contact Details', $xml, $result);

        $coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
        $msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;

        # Check result
        if(!eppSuccess($coderes)) 
        {
            $values["error"] = "GetContactDetails/domain-info($sld.$tld): Code (".$coderes.") ".$msg;
            return $values;
        }

        # Grab contact Handles
        $registrant = $doc->getElementsByTagName('registrant')->item(0)->nodeValue;
        
        if(empty($registrant)) 
        {
            $values["error"] = "GetContactDetails/domain-info($sld.$tld): Registrant info not available";
            return $values;
        }

        $domaininfo=array();
		for ($i=0; $i<=2; $i++)
		{
            $x=$doc->getElementsByTagName('contact')->item($i);
			if(!empty($x))
			{
                $domaininfo[$doc->getElementsByTagName('contact')->item($i)->getAttribute('type')]=$doc->getElementsByTagName('contact')->item($i)->nodeValue;
            }
			else
			{
                break;
            }
        }

        $contactIDs[$registrant] = array();
		foreach($domaininfo as $id)
		{
			if($id != '')
			{
				$contactIDs[$id] = array();
			}
        }
        
        foreach($contactIDs as $id => $k) 
        {
            if($domaininfo['admin'] == $id)
            {
                $contactIDs[$id] = getContactDetail($client, $id,$params['eppcode'],$doc->getElementsByTagName('roid')->item(0)->nodeValue);
            }
            else
            {
                $contactIDs[$id] = getContactDetail($client, $id,$params['eppcode'],$doc->getElementsByTagName('roid')->item(0)->nodeValue);
            }
            
        }

        $Contacts["Admin"]=$domaininfo["admin"];
        $Contacts["Tech"]=$domaininfo["tech"];
        $Contacts["Billing"]=$domaininfo["billing"];

        # Grab Registrant Contact
        $values["Registrant"] = $contactIDs[$registrant];

        #Get Admin, Tech and Billing Contacts
		foreach ($Contacts as $type => $value)
		{
			if ($value!="")
			{
                $values["$type"] = $contactIDs[$value];
			}
			else
			{
                $values["$type"]["Contact Name"] = "";
                $values["$type"]["Company Name"] = "";
                $values["$type"]["Address 1"] = "";
                $values["$type"]["Address 2"] = "";
                $values["$type"]["City"] = "";
                $values["$type"]["State"] = "";
                $values["$type"]["ZIP code"] = "";
                $values["$type"]["Country"] = "";
                $values["$type"]["Phone"] = "";
                $values["$type"]["Email"] = "";
            }
        }

        return $values;

	}
	catch (Exception $e)
	{
		$values["error"] = 'GetContactDetails/EPP: '.$e->getMessage();
		return $values;
	}
}

function getContactDetail($client, $contactID,$eppcode= '',$rolid='') 
{
    $xml ='<?xml version="1.0" encoding="UTF-8" standalone="no"?>
				<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
					<command>
						<info>
							<contact:info xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
								<contact:id>'.$contactID.'</contact:id>'; 
								if(!empty($eppcode))
								{
									$xml .='<contact:authInfo>
												<contact:pw roid="'.$rolid.'">'.$eppcode.'</contact:pw>
											</contact:authInfo>';
								}
					$xml .= '</contact:info>
						</info>
						<clTRID>'.mt_rand().mt_rand().'</clTRID>
					</command>
				</epp>';
        
    $request =  $client->request($xml);

    # Parse XML result
    $doc= new DOMDocument();
    $doc->loadXML($request);
  /*  logModuleCall('COCCAepp', 'GetContactDetails', $xml, $request); */
    HelperFunction::coccaepp_logModuleCall('COCCAepp','GetContactDetails', $xml, $request);

    $coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
    $msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;

    # Check results
	if(!eppSuccess($coderes))
	{
        throw new Exception("contact-info($contactID): Code (".$coderes.") ".$msg);
    }

    $contact["Contact Name"] = $doc->getElementsByTagName('name')->item(0)->nodeValue;
    $contact["Company Name"] = $doc->getElementsByTagName('org')->item(0)->nodeValue;
    $contact["Position"] = $doc->getElementsByTagName('position')->item(0)->nodeValue;
    $contact["Address 1"] = $doc->getElementsByTagName('street')->item(0)->nodeValue;
    $contact["Address 2"] = $doc->getElementsByTagName('street')->item(1)->nodeValue;
    $contact["City"] = $doc->getElementsByTagName('city')->item(0)->nodeValue;
    $contact["State"] = $doc->getElementsByTagName('sp')->item(0)->nodeValue;
    $contact["ZIP code"] = $doc->getElementsByTagName('pc')->item(0)->nodeValue;
    $contact["Country"] = $doc->getElementsByTagName('cc')->item(0)->nodeValue;
    $contact["Phone"] = $doc->getElementsByTagName('voice')->item(0)->nodeValue;
    $contact["Mobile"] = $doc->getElementsByTagName('mobile')->item(0)->nodeValue;
    $contact["Fax"] = $doc->getElementsByTagName('fax')->item(0)->nodeValue;
    $contact["Email"] = $doc->getElementsByTagName('email')->item(0)->nodeValue;
    $contact["Website"] = $doc->getElementsByTagName('website')->item(0)->nodeValue;

    return $contact;
}

# Function to save contact details
function COCCAepp_SaveContactDetails($params)
{
	global $CONFIG; 
	global $_LANG;
	// if($params["tld"] == "sa" && $_POST['check'] == 0 && $_POST['opp'] == 'save')
	if($_POST['check'] == 0 && $_POST['opp'] == 'save')
	{
        try
        {
            #Function call to create custom table
            coccaepp_schema();
			$where = [
				['domainid', '=', $params["domainid"]],
				['userid', '=', $params["userid"]],
			];
			$count = DB::table('cocca_save_domain_contact')
            		->where($where)->count();
			if($count)
			{
            	return "Request is already pending for administrator approval";
			}
            #Generate Random otp + authkey
            $otp = (string)mt_rand(111111, 999999);
			$encryptauthkey = substr(md5(time()), 0, 16);

            #Email send
            $msg = $_LANG['dear_administrator'].",<br><br><br>".
            $_LANG['for_your_updation_request_email_statement']."<br><br><br>";
			$msg .= "<a href='".$CONFIG['SystemURL']."/updatedomaincontact.php?domainid=".$params['domainid']."&userid=".$params['userid']."&authkey=".urlencode($encryptauthkey)."' target='_blank'><button type='button'>".$_LANG['click_to_proceed']."</button></a><br>";

			 $getConatct =   COCCAepp_GetContactDetails($params);
			 $adminEmail =  $getConatct['Admin']['Email'];
			 $subject = $_LANG['whois_update_approval_request_for_contact_detail']." : ".$domain;
			 include_once dirname(__FILE__) . '/mail.php';
			 $status = emailtouser($adminEmail, $subject, $msg);
			if($status)
			{
				logActivity("***--Domain Update Approval Request Email for Contact Detail has been sent.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
				HelperFunction::coccaepp_approval_logModuleCall($params['domainname'], 'Send Email: Domain Update Approval Request Email for Contact Detail', 'Domain Update Contact','Email Sent Success');
			}
			else
			{
				logActivity("***--Domain Update Approval Request Email for Contact Detail has been failed.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
				HelperFunction::coccaepp_approval_logModuleCall($params['domainname'], 'Send Email failed: Domain Update Approval Request Email for Contact Detail', 'Domain Update Contact','Email Sent Failed');
			}

			//send OTP on Phone number
			include_once dirname(__FILE__) . '/send_sms.php';
			$countrycode=explode('.',$getConatct['Admin']['Mobile'])[0];
			$number=explode('.',$getConatct['Admin']['Mobile'])[1];
			$send = sendsms($countrycode, $number, $domain, $otp);
			if($send){
				logActivity("***--Contact Detail Update OTP has been sent successfully.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
				HelperFunction::coccaepp_approval_logModuleCall($params['domainname'], 'Send SMS: Domain Update Approval Request SMS for Contact Detail', 'Domain Update Contact','SMS Sent Success');
			}
			else
			{
				logActivity("***--Contact Detail Update send OTP has been failed.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
					HelperFunction::coccaepp_approval_logModuleCall($params['domainname'], 'Send SMS failed: Domain Update Approval Request SMS for Contact Detail', 'Domain Update Contact','SMS Sent failed');
			}

			//Insert Custom data 
			$data = serialize($params);
			$insert = array(
                'domainid' => $params["domainid"],
                'userid' => $params["userid"],
                'contactdetail' => $data,
                'auth_key' => $encryptauthkey,
                'otp' => $otp,
            );
            coccaepp_insertData("cocca_save_domain_contact", $insert, $params["userid"]); 
            return "success";
        }
		catch (Exception $e)
		{
            logActivity("ERROR :".$e->getMessage(), $params["userid"]);
			return ["error" =>"ERROR :".$e->getMessage()];
		}

	}
	else if($_POST['check'] == 1 && $_POST['opp'] == 'cancel')
	{
        try
        {
			$where = [
						['domainid', '=', $params["domainid"]],
						['userid', '=', $params["userid"]]
					];
			DB::table('cocca_save_domain_contact')->where($where)->delete();
			logActivity("***--Update contact detail request has been cancelled.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
		}
		catch (Exception $e)
		{
            logActivity("ERROR :".$e->getMessage(), $params["userid"]);
		}
		return "success";
	}

	# Grab variables
	$tld = $params["tld"];
	$sld = $params["sld"];
        $details = $params["contactdetails"];
	$domain = "$sld.$tld";
	# Get client instance
	try
	{
		$client = _COCCAepp_Client($params);
		
        # Grab domain info
        $request = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
												<epp:epp xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:epp="urn:ietf:params:xml:ns:epp-1.0"
														xmlns:domain="urn:ietf:params:xml:ns:domain-1.0" xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
													<epp:command>
														<epp:info>
															<domain:info xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
																<domain:name hosts="all">' . $sld . '.' . $tld . '</domain:name>
															</domain:info>
														</epp:info>
													</epp:command>
												</epp:epp>');

        # Parse XML	result
        $doc = new DOMDocument();
        $doc->loadXML($request);
        $coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
        $msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;
		if (!eppSuccess($coderes))
		{
            $values["error"] = "SaveContactDetails/domain-info($sld.$tld): Code (" . $coderes . ") " . $msg;
            return $values;
        }

        $values["status"] = $msg;
        # Grab Registrant contact Handles
        $registrantHandle = $doc->getElementsByTagName('registrant')->item(0)->nodeValue;
		if (empty($registrantHandle))
		{
            $values["error"] = "GetContactDetails/domain-info($sld.$tld): Registrant info not available";
            return $values;
        }
        $domaininfo = array();
		for ($i = 0; $i <= 2; $i++)
		{
            $x = $doc->getElementsByTagName('contact')->item($i);
			if (!empty($x))
			{
                $domaininfo[$doc->getElementsByTagName('contact')->item($i)->getAttribute('type')] = $doc->getElementsByTagName('contact')->item($i)->nodeValue;
			}
			else
			{
                break;
            }
        }

        $Contacts["Admin"] = $domaininfo["admin"];
        $Contacts["Tech"] = $domaininfo["tech"];
        $Contacts["Billing"] = $domaininfo["billing"];

        $cIDs[$registrantHandle] = 'Registrant';
		foreach($Contacts as $type => $handle)
		{
			if(isset($handle))
			{
				if(!array_key_exists($handle, $cIDs))
				{
                    $cIDs[$handle] = $type;
                }
				else
				{
                    $removeContact[$type] = $handle;
                    $Contacts[$type] = null;
				}
			}
        }

		foreach ($Contacts as $type => $handle)
		{
			if (isset($handle))
			{
				if (!array_empty($details[$type]))
				{
					changeContact($client, $details[$type], $handle, $type);
				}
				else
				{
					$removeContact[$type] = $handle;
				}
			}
			else
			{
				if (!array_empty($details[$type]))
				{
					$addContact[$type] = createContact($client, $details[$type], $type);
				}
            }
        }

        $xmlAddContact = '';
		if(isset($addContact))
		{
            $xmlAddContact = "<domain:add>\n";
			foreach ($addContact as $type => $handle)
			{
                $xmlAddContact .= '<domain:contact type="'.strtolower($type).'">'.$handle.'</domain:contact>'."\n";
            }
            $xmlAddContact .= "</domain:add>\n";
        }

        $xmlRemContact = '';
		if(isset($removeContact))
		{
            $xmlRemContact = "<domain:rem>\n";
			foreach ($removeContact as $type => $handle)
			{
                $xmlRemContact .= '<domain:contact type="'.strtolower($type).'">'.$handle.'</domain:contact>'."\n";
            }
            $xmlRemContact .= "</domain:rem>\n";
        }

        # Save Registrant contact details
        changeContact($client, $details['Registrant'], $registrantHandle, "Registrant");

        # change the domain contacts
		if(!empty($xmlAddContact) || !empty($xmlRemContact))
		{
            $request = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
													<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
														<command>
															<update>
																<domain:update xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
																	<domain:name>' . $sld . '.' . $tld . '</domain:name>' .
																		$xmlAddContact .
																		$xmlRemContact . '
																</domain:update>
															</update>
															<clTRID>' . mt_rand() . mt_rand() . '</clTRID>
														</command>
													</epp>');

            $doc = new DOMDocument();
            $doc->loadXML($request);
          /*  logModuleCall('COCCAepp', 'SaveContactDetails', $xml, $request); */
            HelperFunction::coccaepp_logModuleCall('COCCAepp','SaveContactDetails', $xml, $request);

            $coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
            $msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;
			if (!eppSuccess($coderes))
			{
                $values["error"] = "Domain contact update error: Code ($coderes) $msg";
                return $values;
            }

            $values["status"] = $msg;
          /*   COCCAepp_GetEPPCode($params); */
        }
		else
		{
            $values["status"] = 'OK';
        }

	}
	catch (Exception $e)
	{
		$values["error"] = 'SaveContactDetails/EPP: '.$e->getMessage();
		return $values;
	}
	return $values;

}
 
function createContact($client, $data, $type = "")
{
    //Create Billing Contacts
    $handle = generateHandle();
    $eppKey =  authKey();
if (empty($data["Company Name"]) and empty($data["Address 2"]) and empty($data["Fax"])) {
    $result = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
<command>
<create>
<contact:create xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
<contact:id>'.$handle.'</contact:id>
<contact:postalInfo type="int">
<contact:name>'.htmlspecialchars($data["Contact Name"]).' </contact:name>

<contact:addr>
<contact:street>'.htmlspecialchars($data["Address 1"]).'</contact:street>

<contact:city>'.htmlspecialchars($data["City"]).'</contact:city>
<contact:sp>'.htmlspecialchars($data["State"]).'</contact:sp>
<contact:pc>'.$data["ZIP code"].'</contact:pc>
<contact:cc>'.$data["Country"].'</contact:cc>
</contact:addr>
</contact:postalInfo>
<contact:postalInfo type="loc">
<contact:name>'.htmlspecialchars($data["Contact Name"]).' </contact:name>

<contact:addr>
<contact:street>'.htmlspecialchars($data["Address 1"]).'</contact:street>

<contact:city>'.htmlspecialchars($data["City"]).'</contact:city>
<contact:sp>'.htmlspecialchars($data["State"]).'</contact:sp>
<contact:pc>'.$data["ZIP code"].'</contact:pc>
<contact:cc>'.$data["Country"].'</contact:cc>
</contact:addr>
</contact:postalInfo>
<contact:voice>'.$data["Phone"].'</contact:voice>

<contact:email>'.$data["Email"].'</contact:email>
          <contact:authInfo>
            <contact:pw>'.$eppKey.'</contact:pw>
          </contact:authInfo>
        </contact:create>
</create>
<extension>
<snic:contactInfo xmlns:snic="urn:saudinic:params:xml:ns:saudinic-1.0">
<snic:website>'.$data["Website"].'</snic:website>
<snic:mobile>'.$data["Mobile"].'</snic:mobile>
<snic:position>'.htmlspecialchars($data["Position"]).'</snic:position>
</snic:contactInfo>
</extension>
<clTRID>'.mt_rand().mt_rand().'</clTRID>
</command>
</epp>');
} else if (!empty($data["Company Name"]) and !empty($data["Address 2"]) and !empty($data["Fax"])) {
    $result = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
<command>
<create>
<contact:create xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
<contact:id>'.$handle.'</contact:id>

<contact:postalInfo type="int">
<contact:name>'.htmlspecialchars($data["Contact Name"]).' </contact:name>
<contact:org>'.htmlspecialchars($data["Company Name"]).'</contact:org>
<contact:addr>
<contact:street>'.htmlspecialchars($data["Address 1"]).'</contact:street>
<contact:street>'.htmlspecialchars($data["Address 2"]).'</contact:street>
<contact:city>'.htmlspecialchars($data["City"]).'</contact:city>
<contact:sp>'.htmlspecialchars($data["State"]).'</contact:sp>
<contact:pc>'.$data["ZIP code"].'</contact:pc>
<contact:cc>'.$data["Country"].'</contact:cc>
</contact:addr>
</contact:postalInfo>
<contact:postalInfo type="loc">
<contact:name>'.htmlspecialchars($data["Contact Name"]).' </contact:name>
<contact:org>'.htmlspecialchars($data["Company Name"]).'</contact:org>
<contact:addr>
<contact:street>'.htmlspecialchars($data["Address 1"]).'</contact:street>
<contact:street>'.htmlspecialchars($data["Address 2"]).'</contact:street>
<contact:city>'.htmlspecialchars($data["City"]).'</contact:city>
<contact:sp>'.htmlspecialchars($data["State"]).'</contact:sp>
<contact:pc>'.$data["ZIP code"].'</contact:pc>
<contact:cc>'.$data["Country"].'</contact:cc>
</contact:addr>
</contact:postalInfo>
<contact:voice>'.$data["Phone"].'</contact:voice>
<contact:fax>'.$data["Fax"].'</contact:fax>
<contact:email>'.$data["Email"].'</contact:email>

          <contact:authInfo>
            <contact:pw>'.$eppKey.'</contact:pw>
          </contact:authInfo>
        </contact:create>
</create>
<extension>
<snic:contactInfo xmlns:snic="urn:saudinic:params:xml:ns:saudinic-1.0">
<snic:website>'.$data["Website"].'</snic:website>
<snic:mobile>'.$data["Mobile"].'</snic:mobile>
<snic:position>'.htmlspecialchars($data["Position"]).'</snic:position>
</snic:contactInfo>
</extension>
<clTRID>'.mt_rand().mt_rand().'</clTRID>
</command>
</epp>');
} else if (!empty($data["Company Name"]) and !empty($data["Address 2"]) and empty($data["Fax"])) {
    $result = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
<command>
<create>
<contact:create xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
<contact:id>'.$handle.'</contact:id>

<contact:postalInfo type="int">
<contact:name>'.htmlspecialchars($data["Contact Name"]).' </contact:name>
<contact:org>'.htmlspecialchars($data["Company Name"]).'</contact:org>
<contact:addr>
<contact:street>'.htmlspecialchars($data["Address 1"]).'</contact:street>
<contact:street>'.htmlspecialchars($data["Address 2"]).'</contact:street>
<contact:city>'.htmlspecialchars($data["City"]).'</contact:city>
<contact:sp>'.htmlspecialchars($data["State"]).'</contact:sp>
<contact:pc>'.$data["ZIP code"].'</contact:pc>
<contact:cc>'.$data["Country"].'</contact:cc>
</contact:addr>
</contact:postalInfo>
<contact:postalInfo type="loc">
<contact:name>'.htmlspecialchars($data["Contact Name"]).' </contact:name>
<contact:org>'.htmlspecialchars($data["Company Name"]).'</contact:org>
<contact:addr>
<contact:street>'.htmlspecialchars($data["Address 1"]).'</contact:street>
<contact:street>'.htmlspecialchars($data["Address 2"]).'</contact:street>
<contact:city>'.htmlspecialchars($data["City"]).'</contact:city>
<contact:sp>'.htmlspecialchars($data["State"]).'</contact:sp>
<contact:pc>'.$data["ZIP code"].'</contact:pc>
<contact:cc>'.$data["Country"].'</contact:cc>
</contact:addr>
</contact:postalInfo>
<contact:voice>'.$data["Phone"].'</contact:voice>

<contact:email>'.$data["Email"].'</contact:email>

          <contact:authInfo>
            <contact:pw>'.$eppKey.'</contact:pw>
          </contact:authInfo>
        </contact:create>
</create>
<extension>
<snic:contactInfo xmlns:snic="urn:saudinic:params:xml:ns:saudinic-1.0">
<snic:website>'.$data["Website"].'</snic:website>
<snic:mobile>'.$data["Mobile"].'</snic:mobile>
<snic:position>'.htmlspecialchars($data["Position"]).'</snic:position>
</snic:contactInfo>
</extension>
<clTRID>'.mt_rand().mt_rand().'</clTRID>
</command>
</epp>');
} else if (!empty($data["Company Name"]) and empty($data["Address 2"]) and empty($data["Fax"])) {
    $result = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
<command>
<create>
<contact:create xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
<contact:id>'.$handle.'</contact:id>

<contact:postalInfo type="int">
<contact:name>'.htmlspecialchars($data["Contact Name"]).' </contact:name>
<contact:org>'.htmlspecialchars($data["Company Name"]).'</contact:org>
<contact:addr>
<contact:street>'.htmlspecialchars($data["Address 1"]).'</contact:street>

<contact:city>'.htmlspecialchars($data["City"]).'</contact:city>
<contact:sp>'.htmlspecialchars($data["State"]).'</contact:sp>
<contact:pc>'.$data["ZIP code"].'</contact:pc>
<contact:cc>'.$data["Country"].'</contact:cc>
</contact:addr>
</contact:postalInfo>
<contact:postalInfo type="loc">
<contact:name>'.htmlspecialchars($data["Contact Name"]).' </contact:name>
<contact:org>'.htmlspecialchars($data["Company Name"]).'</contact:org>
<contact:addr>
<contact:street>'.htmlspecialchars($data["Address 1"]).'</contact:street>

<contact:city>'.htmlspecialchars($data["City"]).'</contact:city>
<contact:sp>'.htmlspecialchars($data["State"]).'</contact:sp>
<contact:pc>'.$data["ZIP code"].'</contact:pc>
<contact:cc>'.$data["Country"].'</contact:cc>
</contact:addr>
</contact:postalInfo>
<contact:voice>'.$data["Phone"].'</contact:voice>

<contact:email>'.$data["Email"].'</contact:email>

          <contact:authInfo>
            <contact:pw>'.$eppKey.'</contact:pw>
          </contact:authInfo>
        </contact:create>
</create>
<extension>
<snic:contactInfo xmlns:snic="urn:saudinic:params:xml:ns:saudinic-1.0">
<snic:website>'.$data["Website"].'</snic:website>
<snic:mobile>'.$data["Mobile"].'</snic:mobile>
<snic:position>'.htmlspecialchars($data["Position"]).'</snic:position>
</snic:contactInfo>
</extension>
<clTRID>'.mt_rand().mt_rand().'</clTRID>
</command>
</epp>');
} else if (!empty($data["Company Name"]) and empty($data["Address 2"]) and !empty($data["Fax"])) {
    $result = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
<command>
<create>
<contact:create xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
<contact:id>'.$handle.'</contact:id>

<contact:postalInfo type="int">
<contact:name>'.htmlspecialchars($data["Contact Name"]).' </contact:name>
<contact:org>'.htmlspecialchars($data["Company Name"]).'</contact:org>
<contact:addr>
<contact:street>'.htmlspecialchars($data["Address 1"]).'</contact:street>

<contact:city>'.htmlspecialchars($data["City"]).'</contact:city>
<contact:sp>'.htmlspecialchars($data["State"]).'</contact:sp>
<contact:pc>'.$data["ZIP code"].'</contact:pc>
<contact:cc>'.$data["Country"].'</contact:cc>
</contact:addr>
</contact:postalInfo>
<contact:postalInfo type="loc">
<contact:name>'.htmlspecialchars($data["Contact Name"]).' </contact:name>
<contact:org>'.htmlspecialchars($data["Company Name"]).'</contact:org>
<contact:addr>
<contact:street>'.htmlspecialchars($data["Address 1"]).'</contact:street>

<contact:city>'.htmlspecialchars($data["City"]).'</contact:city>
<contact:sp>'.htmlspecialchars($data["State"]).'</contact:sp>
<contact:pc>'.$data["ZIP code"].'</contact:pc>
<contact:cc>'.$data["Country"].'</contact:cc>
</contact:addr>
</contact:postalInfo>
<contact:voice>'.$data["Phone"].'</contact:voice>
<contact:fax>'.$data["Fax"].'</contact:fax>
<contact:email>'.$data["Email"].'</contact:email>

          <contact:authInfo>
            <contact:pw>'.$eppKey.'</contact:pw>
          </contact:authInfo>
        </contact:create>
</create>
<extension>
<snic:contactInfo xmlns:snic="urn:saudinic:params:xml:ns:saudinic-1.0">
<snic:website>'.$data["Website"].'</snic:website>
<snic:mobile>'.$data["Mobile"].'</snic:mobile>
<snic:position>'.htmlspecialchars($data["Position"]).'</snic:position>
</snic:contactInfo>
</extension>
<clTRID>'.mt_rand().mt_rand().'</clTRID>
</command>
</epp>');
} else if (empty($data["Company Name"]) and !empty($data["Address 2"]) and !empty($data["Fax"])) {
    $result = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
<command>
<create>
<contact:create xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
<contact:id>'.$handle.'</contact:id>

<contact:postalInfo type="int">
<contact:name>'.htmlspecialchars($data["Contact Name"]).' </contact:name>

<contact:addr>
<contact:street>'.htmlspecialchars($data["Address 1"]).'</contact:street>
<contact:street>'.htmlspecialchars($data["Address 2"]).'</contact:street>
<contact:city>'.htmlspecialchars($data["City"]).'</contact:city>
<contact:sp>'.htmlspecialchars($data["State"]).'</contact:sp>
<contact:pc>'.$data["ZIP code"].'</contact:pc>
<contact:cc>'.$data["Country"].'</contact:cc>
</contact:addr>
</contact:postalInfo>
<contact:postalInfo type="loc">
<contact:name>'.htmlspecialchars($data["Contact Name"]).' </contact:name>

<contact:addr>
<contact:street>'.htmlspecialchars($data["Address 1"]).'</contact:street>
<contact:street>'.htmlspecialchars($data["Address 2"]).'</contact:street>
<contact:city>'.htmlspecialchars($data["City"]).'</contact:city>
<contact:sp>'.htmlspecialchars($data["State"]).'</contact:sp>
<contact:pc>'.$data["ZIP code"].'</contact:pc>
<contact:cc>'.$data["Country"].'</contact:cc>
</contact:addr>
</contact:postalInfo>
<contact:voice>'.$data["Phone"].'</contact:voice>

<contact:email>'.$data["Email"].'</contact:email>

          <contact:authInfo>
            <contact:pw>'.$eppKey.'</contact:pw>
          </contact:authInfo>
        </contact:create>
</create>
<extension>
<snic:contactInfo xmlns:snic="urn:saudinic:params:xml:ns:saudinic-1.0">
<snic:website>'.$data["Website"].'</snic:website>
<snic:mobile>'.$data["Mobile"].'</snic:mobile>
<snic:position>'.htmlspecialchars($data["Position"]).'</snic:position>
</snic:contactInfo>
</extension>
<clTRID>'.mt_rand().mt_rand().'</clTRID>
</command>
</epp>');
} else if (empty($data["Company Name"]) and empty($data["Address 2"]) and !empty($data["Fax"])) {
    $result = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
<command>
<create>
<contact:create xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
<contact:id>'.$handle.'</contact:id>

<contact:postalInfo type="int">
<contact:name>'.htmlspecialchars($data["Contact Name"]).' </contact:name>

<contact:addr>
<contact:street>'.htmlspecialchars($data["Address 1"]).'</contact:street>

<contact:city>'.htmlspecialchars($data["City"]).'</contact:city>
<contact:sp>'.htmlspecialchars($data["State"]).'</contact:sp>
<contact:pc>'.$data["ZIP code"].'</contact:pc>
<contact:cc>'.$data["Country"].'</contact:cc>
</contact:addr>
</contact:postalInfo>
<contact:postalInfo type="loc">
<contact:name>'.htmlspecialchars($data["Contact Name"]).' </contact:name>

<contact:addr>
<contact:street>'.htmlspecialchars($data["Address 1"]).'</contact:street>

<contact:city>'.htmlspecialchars($data["City"]).'</contact:city>
<contact:sp>'.htmlspecialchars($data["State"]).'</contact:sp>
<contact:pc>'.$data["ZIP code"].'</contact:pc>
<contact:cc>'.$data["Country"].'</contact:cc>
</contact:addr>
</contact:postalInfo>
<contact:voice>'.$data["Phone"].'</contact:voice>
<contact:fax>'.$data["Fax"].'</contact:fax>
<contact:email>'.$data["Email"].'</contact:email>

          <contact:authInfo>
            <contact:pw>'.$eppKey.'</contact:pw>
          </contact:authInfo>
        </contact:create>
</create>
<extension>
<snic:contactInfo xmlns:snic="urn:saudinic:params:xml:ns:saudinic-1.0">
<snic:website>'.$data["Website"].'</snic:website>
<snic:mobile>'.$data["Mobile"].'</snic:mobile>
<snic:position>'.htmlspecialchars($data["Position"]).'</snic:position>
</snic:contactInfo>
</extension>
<clTRID>'.mt_rand().mt_rand().'</clTRID>
</command>
</epp>');
} else if (empty($data["Company Name"]) and !empty($data["Address 2"]) and empty($data["Fax"])) {
    $result = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
<command>
<create>
<contact:create xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
<contact:id>'.$handle.'</contact:id>

<contact:postalInfo type="int">
<contact:name>'.htmlspecialchars($data["Contact Name"]).' </contact:name>

<contact:addr>
<contact:street>'.htmlspecialchars($data["Address 1"]).'</contact:street>
<contact:street>'.htmlspecialchars($data["Address 2"]).'</contact:street>
<contact:city>'.htmlspecialchars($data["City"]).'</contact:city>
<contact:sp>'.htmlspecialchars($data["State"]).'</contact:sp>
<contact:pc>'.$data["ZIP code"].'</contact:pc>
<contact:cc>'.$data["Country"].'</contact:cc>
</contact:addr>
</contact:postalInfo>
<contact:postalInfo type="loc">
<contact:name>'.htmlspecialchars($data["Contact Name"]).' </contact:name>

<contact:addr>
<contact:street>'.htmlspecialchars($data["Address 1"]).'</contact:street>
<contact:street>'.htmlspecialchars($data["Address 2"]).'</contact:street>
<contact:city>'.htmlspecialchars($data["City"]).'</contact:city>
<contact:sp>'.htmlspecialchars($data["State"]).'</contact:sp>
<contact:pc>'.$data["ZIP code"].'</contact:pc>
<contact:cc>'.$data["Country"].'</contact:cc>
</contact:addr>
</contact:postalInfo>
<contact:voice>'.$data["Phone"].'</contact:voice>

<contact:email>'.$data["Email"].'</contact:email>

          <contact:authInfo>
            <contact:pw>'.$eppKey.'</contact:pw>
          </contact:authInfo>
        </contact:create>
</create>
<extension>
<snic:contactInfo xmlns:snic="urn:saudinic:params:xml:ns:saudinic-1.0">
<snic:website>'.$data["Website"].'</snic:website>
<snic:mobile>'.$data["Mobile"].'</snic:mobile>
<snic:position>'.htmlspecialchars($data["Position"]).'</snic:position>
</snic:contactInfo>
</extension>
<clTRID>'.mt_rand().mt_rand().'</clTRID>
</command>
</epp>');
}


    # Parse XML result
    $doc = new DOMDocument();
    $doc->loadXML($result);
   /* logModuleCall('COCCAepp', 'CreateContactDetails', $xml, $result); */
     HelperFunction::coccaepp_logModuleCall('COCCAepp','CreateContactDetails', $xml, $result);

    $coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
    $msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;
	if(!eppSuccess($coderes))
	{
        throw new Exception("contact-create($handle) $type: Code ($coderes) $msg");
    }

    return $handle;
}

function COCCAepp_UpdateFieldsAdditional($params)
{
    $tld = $params["tld"];
    $sld = $params["sld"];
    $domain = "$sld.$tld";
	if ($tld == 'ma')
	{
		try
		{ 
			$client = _COCCAepp_Client();

			$request1 = $client->request($xml='<?xml version="1.0" encoding="UTF-8" standalone="no"?>
													<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
														<command>
															<info>
																<domain:info xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
																	<domain:name>'.$domain.'</domain:name>
																</domain:info>
															</info>
															<clTRID>'.mt_rand().mt_rand().'</clTRID>
														</command>
													</epp>');

			$doc= new DOMDocument();
			$doc->loadXML($request1);
			$coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
			$msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;
			if (!eppSuccess($coderes))
			{
				$values["error"] = "UpdateFieldsAdditional/domain-info('.$domain.'): Code (" . $coderes . ") " . $msg;
				return $values;
			}

			if($coderes == '1000')
			{
                $registrantid = $doc->getElementsByTagName('registrant')->item(0)->nodeValue ;
                $registrantContact=getContactDetail($client, $registrantid);
				$result = getNID($domain);
				$typo= $result['Type'];
				$IDvalue = $result['Value'];
				switch('.' . $tld)
				{
					case '.as':
						if ($result['Type'] == 'IND' )
						{
							$RegistrantType= 'IND';
							$RegistrantNID= $result['Value'];
							$RegistrantTID = $result['Value'];
						}
						else
						{
							$RegistrantType= 'ORG';
							$RegistrantNID=  $result['Value'];
							$RegistrantTID = $result['Value'];
						}
       				case '.ote.as':
					  	if ($result['Type'] == 'IND' )
					  	{
                          	$RegistrantType= 'IND';
                          	$RegistrantNID= $result['Value'];
                          	$RegistrantTID = $result['Value'];
						}
						else
						{
                        	$RegistrantType= 'ORG';
                          	$RegistrantNID=  $result['Value'];
                          	$RegistrantTID = $result['Value']; 
                        }
                }
				if (!empty($RegistrantType) && $RegistrantType=='IND' )
				{
					$result = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
															<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
																<command>
																	<update>
																		<contact:update xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
																			<contact:id>'.$registrantid.'</contact:id>
																			<contact:chg>
																				<contact:postalInfo type="int">
																					<contact:name>'.htmlspecialchars($registrantContact["Contact Name"]).' </contact:name>
																					<contact:org>'.htmlspecialchars($registrantContact["Company Name"]).'</contact:org>
																					<contact:addr>
																						<contact:street>'.htmlspecialchars($registrantContact["Address 1"]).'</contact:street>
																						<contact:street>'.htmlspecialchars($registrantContact["Address 2"]).'</contact:street>
																						<contact:city>'.htmlspecialchars($registrantContact["City"]).'</contact:city>
																						<contact:sp>'.htmlspecialchars($registrantContact["State"]).'</contact:sp>
																						<contact:pc>'.$registrantContact["ZIP code"].'</contact:pc>
																						<contact:cc>'.$registrantContact["Country"].'</contact:cc>
																					</contact:addr>
																				</contact:postalInfo>
																				<contact:voice>'.$registrantContact["Phone"].'</contact:voice>
																				<contact:email>'.$registrantContact["Email"].'</contact:email>
																			</contact:chg>
																		</contact:update>
																	</update>
																	<extension>
																		<contact:update xmlns:contact="urn:ietf:params:xml:ns:contact-id-1.0">
																			<contact:person>
																				<contact:NID>'.$RegistrantNID.'</contact:NID>
																			</contact:person>
																		</contact:update>
																	</extension>
																	<clTRID>'.mt_rand().mt_rand().'</clTRID>
																</command>
															</epp>');
				}
				else
				{
					$result = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
															<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
																<command>
																	<update>
																		<contact:update xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
																			<contact:id>'.$registrantid.'</contact:id>
																			<contact:chg>
																				<contact:postalInfo type="loc">
																					<contact:name>'.$registrantContact["Contact Name"].' </contact:name>
																					<contact:org>'.$registrantContact["Company Name"].'</contact:org>
																					<contact:addr>
																						<contact:street>'.$registrantContact["Address 1"].'</contact:street>
																						<contact:street>'.$registrantContact["Address 2"].'</contact:street>
																						<contact:city>'.$registrantContact["City"].'</contact:city>
																						<contact:sp>'.$registrantContact["State"].'</contact:sp>
																						<contact:pc>'.$registrantContact["ZIP code"].'</contact:pc>
																						<contact:cc>'.$registrantContact["Country"].'</contact:cc>
																					</contact:addr>
																				</contact:postalInfo>
																				<contact:voice>'.$registrantContact["Phone"].'</contact:voice>
																				<contact:email>'.$registrantContact["Email"].'</contact:email>
																			</contact:chg>
																		</contact:update>
																	</update>
																	<extension>
																		<contact:update xmlns:contact="urn:ietf:params:xml:ns:contact-id-1.0">
																			<contact:organization>
																				<contact:NID>'.$RegistrantTID.'</contact:NID>
																			</contact:organization>
																		</contact:update>
																	</extension>
																	<clTRID>'.mt_rand().mt_rand().'</clTRID>
																</command>
															</epp>');

				}
				# Parse XML result
				$doc = new DOMDocument();
				$doc->loadXML($result);
			/*	logModuleCall('COCCAepp', 'SaveContactDetailsWithAddionalFields', $xml, $result); */
				HelperFunction::coccaepp_logModuleCall('COCCAepp','SaveContactDetailsWithAddionalFields', $xml, $result);

				$coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
				$msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;
				if(!eppSuccess($coderes))
				{
					throw new Exception("contact-create($handle) $type: Code ($coderes) $msg");
				}
			}
			else
			{
				$values["error"] = "Save additional fields/('.$domain.'): Code (" . $coderes . ") " . $msg;
				return  $values; 
			}
		}
		catch (Exception $e)
		{
			$values["error"] = 'Save Additional Fields/EPP: '.$e->getMessage();
			return $values; 
		}
	}
}

// Restore function by Alsayed

    function COCCAepp_RestoreDomain($params) {
            $sld = $params['sld'];
            $tld = $params['tld'];
    $domain = "$sld.$tld";
            #
            try {
                    $client = _COCCAepp_Client();
    
                    # Approve Transfer Request
                    $request = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
       <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
            <command xmlns="urn:ietf:params:xml:ns:epp-1.0">
                                                       <update>
                                                               <domain:update xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
                                                               <domain:name>'.$sld.'.'.$tld.'</domain:name>
                                                               <domain:chg/>
                                                             </domain:update>
                                                       </update>
                                                       <extension>
                                                             <rgp:update xmlns:rgp="urn:ietf:params:xml:ns:rgp-1.0">
                                                               <rgp:restore op="request"/>
                                                             </rgp:update>
                                                       </extension>
    <clTRID>'.mt_rand().mt_rand().'</clTRID>
    </command>
    </epp>
    ');
    
                    # Parse XML result
                    $doc = new DOMDocument();
                    $doc->loadXML($request);
                 /*   logModuleCall('COCCAepp', 'RestoreDomain', $xml, $request); */
                    HelperFunction::coccaepp_logModuleCall('COCCAepp','RestoreDomain', $xml, $request);
    
                    $coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
                    $msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;
    
                    # Check result
                    if(!eppSuccess($coderes)) {
                            $values['error'] = 'RestoreDomain/domain-info('.$sld.'.'.$tld.'): Code('._COCCAepp_message($coderes).") $msg";
                            return $values;
                    }
    
                    $values['status'] = $msg;
    
            } catch (Exception $e) {
                    $values["error"] = 'RestoreDomain/EPP: '.$e->getMessage();
                    return $values;
            }
    
            return $values;
    }

// testing by bashar
/*
// activatng Variants
function COCCAepp_ActivateVariants($params)
{
	$tld = $params["tld"];
	$sld = $params["sld"];
	$domain = "$sld.$tld";
	try
	{
		$client = _COCCAepp_Client();
     	$request1 = $client->request($xml='<?xml version="1.0" encoding="UTF-8" standalone="no"?>
												<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
													<command xmlns="urn:ietf:params:xml:ns:epp-1.0">
														<update>
															<domain:update xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
																<domain:name>'.$domain.'</domain:name>
															</domain:update>
														</update>
														<extension>
															<variant:update xmlns:variant="urn:ar:params:xml:ns:variant-1.1">
																<variant:add>
																	<variant:variant>ألسعودية.السعودية</variant:variant>
																	<variant:variant>ألسعوديه.السعودية</variant:variant>
																	<variant:variant>السعوديه.السعودية</variant:variant>
																	<variant:variant>إلسعوديه.السعو��ية</variant:variant>
																	<variant:variant>إلسعودية.السعودية</variant:variant>
																</variant:add>
															</variant:update>
														</extension>
														<clTRID>'.mt_rand().mt_rand().'</clTRID>
													</command>
												</epp>');
		# Parse XML result
		$doc= new DOMDocument();
		$doc->loadXML($request);
		logModuleCall('COCCAepp', 'ActivateVariants', $xml, $request1);
		$doc= new DOMDocument();
		$doc->loadXML($request1);
		$coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
		$msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;
		if (!eppSuccess($coderes))
		{
			$values["error"] = "ActivateVariants/domain-info('.$domain.'): Code (" . $coderes . ") " . $msg;
			return $values;
		}
	}
	catch (Exception $e)
	{
		$values["error"] = 'Activating Variants Failed: '.$e->getMessage();
		return $values;
	} 
}

// Deleting Variants
function COCCAepp_DeleteVariants($params)
{
	$tld = $params["tld"];
	$sld = $params["sld"];
	$domain = "$sld.$tld";
	try
	{
		$client = _COCCAepp_Client(); 
     	$request1 = $client->request($xml='<?xml version="1.0" encoding="UTF-8" standalone="no"?>
												<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
													<command xmlns="urn:ietf:params:xml:ns:epp-1.0">
														<update>
															<domain:update xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
																<domain:name>'.$domain.'</domain:name>
															</domain:update>
														</update>
														<extension>
															<variant:update xmlns:variant="urn:ar:params:xml:ns:variant-1.1">
																<variant:rem>
																	<variant:variant>إلسعوديه.السعودية</variant:variant>
																	<variant:variant>إلسعودية.السعودية</variant:variant>
																</variant:rem>
															</variant:update>
														</extension>
														<clTRID>'.mt_rand().mt_rand().'</clTRID>
													</command>
												</epp>');
		# Parse XML result
		$doc= new DOMDocument();
		$doc->loadXML($request);
		logModuleCall('COCCAepp', 'DeleteVariants', $xml, $request1);
		$doc= new DOMDocument();
		$doc->loadXML($request1);
		$coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
		$msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;
		if (!eppSuccess($coderes))
		{
			$values["error"] = "DeleteVariants/domain-info('.$domain.'): Code (" . $coderes . ") " . $msg;
			return $values;
		}
	}
	catch (Exception $e)
	{
		$values["error"] = 'Deleting Variants Failed: '.$e->getMessage();
		return $values;
	}
}
*/
// end by testing by bashar

function changeContact($client, $newdata, $handle, $type = "")
{

if (empty($newdata["Company Name"]) and empty($newdata["Address 2"]) and empty($newdata["Fax"])) {
    $result = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
<command>
<update>
<contact:update xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
<contact:id>'.$handle.'</contact:id>
<contact:chg>
<contact:postalInfo type="int">
<contact:name>'.htmlspecialchars($newdata["Contact Name"]).' </contact:name>

<contact:addr>
<contact:street>'.htmlspecialchars($newdata["Address 1"]).'</contact:street>

<contact:city>'.htmlspecialchars($newdata["City"]).'</contact:city>
<contact:sp>'.htmlspecialchars($newdata["State"]).'</contact:sp>
<contact:pc>'.$newdata["ZIP code"].'</contact:pc>
<contact:cc>'.$newdata["Country"].'</contact:cc>
</contact:addr>
</contact:postalInfo>
<contact:postalInfo type="loc">
<contact:name>'.htmlspecialchars($newdata["Contact Name"]).' </contact:name>

<contact:addr>
<contact:street>'.htmlspecialchars($newdata["Address 1"]).'</contact:street>

<contact:city>'.htmlspecialchars($newdata["City"]).'</contact:city>
<contact:sp>'.htmlspecialchars($newdata["State"]).'</contact:sp>
<contact:pc>'.$newdata["ZIP code"].'</contact:pc>
<contact:cc>'.$newdata["Country"].'</contact:cc>
</contact:addr>
</contact:postalInfo>
<contact:voice>'.$newdata["Phone"].'</contact:voice>

<contact:email>'.$newdata["Email"].'</contact:email>
</contact:chg>
</contact:update>
</update>
<extension>
<snic:contactInfo xmlns:snic="urn:saudinic:params:xml:ns:saudinic-1.0">
<snic:website>'.$newdata["Website"].'</snic:website>
<snic:mobile>'.$newdata["Mobile"].'</snic:mobile>
<snic:position>'.htmlspecialchars($newdata["Position"]).'</snic:position>
</snic:contactInfo>
</extension>
<clTRID>'.mt_rand().mt_rand().'</clTRID>
</command>
</epp>');
} else if (!empty($newdata["Company Name"]) and !empty($newdata["Address 2"]) and !empty($newdata["Fax"])) {
    $result = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
<command>
<update>
<contact:update xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
<contact:id>'.$handle.'</contact:id>
<contact:chg>
<contact:postalInfo type="int">
<contact:name>'.htmlspecialchars($newdata["Contact Name"]).' </contact:name>
<contact:org>'.htmlspecialchars($newdata["Company Name"]).'</contact:org>
<contact:addr>
<contact:street>'.htmlspecialchars($newdata["Address 1"]).'</contact:street>
<contact:street>'.htmlspecialchars($newdata["Address 2"]).'</contact:street>
<contact:city>'.htmlspecialchars($newdata["City"]).'</contact:city>
<contact:sp>'.htmlspecialchars($newdata["State"]).'</contact:sp>
<contact:pc>'.$newdata["ZIP code"].'</contact:pc>
<contact:cc>'.$newdata["Country"].'</contact:cc>
</contact:addr>
</contact:postalInfo>
<contact:postalInfo type="loc">
<contact:name>'.htmlspecialchars($newdata["Contact Name"]).' </contact:name>
<contact:org>'.htmlspecialchars($newdata["Company Name"]).'</contact:org>
<contact:addr>
<contact:street>'.htmlspecialchars($newdata["Address 1"]).'</contact:street>
<contact:street>'.htmlspecialchars($newdata["Address 2"]).'</contact:street>
<contact:city>'.htmlspecialchars($newdata["City"]).'</contact:city>
<contact:sp>'.htmlspecialchars($newdata["State"]).'</contact:sp>
<contact:pc>'.$newdata["ZIP code"].'</contact:pc>
<contact:cc>'.$newdata["Country"].'</contact:cc>
</contact:addr>
</contact:postalInfo>
<contact:voice>'.$newdata["Phone"].'</contact:voice>
<contact:fax>'.$newdata["Fax"].'</contact:fax>
<contact:email>'.$newdata["Email"].'</contact:email>
</contact:chg>
</contact:update>
</update>
<extension>
<snic:contactInfo xmlns:snic="urn:saudinic:params:xml:ns:saudinic-1.0">
<snic:website>'.$newdata["Website"].'</snic:website>
<snic:mobile>'.$newdata["Mobile"].'</snic:mobile>
<snic:position>'.htmlspecialchars($newdata["Position"]).'</snic:position>
</snic:contactInfo>
</extension>
<clTRID>'.mt_rand().mt_rand().'</clTRID>
</command>
</epp>');
} else  if (!empty($newdata["Company Name"]) and !empty($newdata["Address 2"]) and empty($newdata["Fax"])) {
    $result = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
<command>
<update>
<contact:update xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
<contact:id>'.$handle.'</contact:id>
<contact:chg>
<contact:postalInfo type="int">
<contact:name>'.htmlspecialchars($newdata["Contact Name"]).' </contact:name>
<contact:org>'.htmlspecialchars($newdata["Company Name"]).'</contact:org>
<contact:addr>
<contact:street>'.htmlspecialchars($newdata["Address 1"]).'</contact:street>
<contact:street>'.htmlspecialchars($newdata["Address 2"]).'</contact:street>
<contact:city>'.htmlspecialchars($newdata["City"]).'</contact:city>
<contact:sp>'.htmlspecialchars($newdata["State"]).'</contact:sp>
<contact:pc>'.$newdata["ZIP code"].'</contact:pc>
<contact:cc>'.$newdata["Country"].'</contact:cc>
</contact:addr>
</contact:postalInfo>
<contact:postalInfo type="loc">
<contact:name>'.htmlspecialchars($newdata["Contact Name"]).' </contact:name>
<contact:org>'.htmlspecialchars($newdata["Company Name"]).'</contact:org>
<contact:addr>
<contact:street>'.htmlspecialchars($newdata["Address 1"]).'</contact:street>
<contact:street>'.htmlspecialchars($newdata["Address 2"]).'</contact:street>
<contact:city>'.htmlspecialchars($newdata["City"]).'</contact:city>
<contact:sp>'.htmlspecialchars($newdata["State"]).'</contact:sp>
<contact:pc>'.$newdata["ZIP code"].'</contact:pc>
<contact:cc>'.$newdata["Country"].'</contact:cc>
</contact:addr>
</contact:postalInfo>
<contact:voice>'.$newdata["Phone"].'</contact:voice>

<contact:email>'.$newdata["Email"].'</contact:email>
</contact:chg>
</contact:update>
</update>
<extension>
<snic:contactInfo xmlns:snic="urn:saudinic:params:xml:ns:saudinic-1.0">
<snic:website>'.$newdata["Website"].'</snic:website>
<snic:mobile>'.$newdata["Mobile"].'</snic:mobile>
<snic:position>'.htmlspecialchars($newdata["Position"]).'</snic:position>
</snic:contactInfo>
</extension>
<clTRID>'.mt_rand().mt_rand().'</clTRID>
</command>
</epp>');
} else if (!empty($newdata["Company Name"]) and empty($newdata["Address 2"]) and empty($newdata["Fax"])) {
    $result = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
<command>
<update>
<contact:update xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
<contact:id>'.$handle.'</contact:id>
<contact:chg>
<contact:postalInfo type="int">
<contact:name>'.htmlspecialchars($newdata["Contact Name"]).' </contact:name>
<contact:org>'.htmlspecialchars($newdata["Company Name"]).'</contact:org>
<contact:addr>
<contact:street>'.htmlspecialchars($newdata["Address 1"]).'</contact:street>

<contact:city>'.htmlspecialchars($newdata["City"]).'</contact:city>
<contact:sp>'.htmlspecialchars($newdata["State"]).'</contact:sp>
<contact:pc>'.$newdata["ZIP code"].'</contact:pc>
<contact:cc>'.$newdata["Country"].'</contact:cc>
</contact:addr>
</contact:postalInfo>
<contact:postalInfo type="loc">
<contact:name>'.htmlspecialchars($newdata["Contact Name"]).' </contact:name>
<contact:org>'.htmlspecialchars($newdata["Company Name"]).'</contact:org>
<contact:addr>
<contact:street>'.htmlspecialchars($newdata["Address 1"]).'</contact:street>

<contact:city>'.htmlspecialchars($newdata["City"]).'</contact:city>
<contact:sp>'.htmlspecialchars($newdata["State"]).'</contact:sp>
<contact:pc>'.$newdata["ZIP code"].'</contact:pc>
<contact:cc>'.$newdata["Country"].'</contact:cc>
</contact:addr>
</contact:postalInfo>
<contact:voice>'.$newdata["Phone"].'</contact:voice>

<contact:email>'.$newdata["Email"].'</contact:email>
</contact:chg>
</contact:update>
</update>
<extension>
<snic:contactInfo xmlns:snic="urn:saudinic:params:xml:ns:saudinic-1.0">
<snic:website>'.$newdata["Website"].'</snic:website>
<snic:mobile>'.$newdata["Mobile"].'</snic:mobile>
<snic:position>'.htmlspecialchars($newdata["Position"]).'</snic:position>
</snic:contactInfo>
</extension>
<clTRID>'.mt_rand().mt_rand().'</clTRID>
</command>
</epp>');
} else if (!empty($newdata["Company Name"]) and empty($newdata["Address 2"]) and !empty($newdata["Fax"])) {
    $result = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
<command>
<update>
<contact:update xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
<contact:id>'.$handle.'</contact:id>
<contact:chg>
<contact:postalInfo type="int">
<contact:name>'.htmlspecialchars($newdata["Contact Name"]).' </contact:name>
<contact:org>'.htmlspecialchars($newdata["Company Name"]).'</contact:org>
<contact:addr>
<contact:street>'.htmlspecialchars($newdata["Address 1"]).'</contact:street>

<contact:city>'.htmlspecialchars($newdata["City"]).'</contact:city>
<contact:sp>'.htmlspecialchars($newdata["State"]).'</contact:sp>
<contact:pc>'.$newdata["ZIP code"].'</contact:pc>
<contact:cc>'.$newdata["Country"].'</contact:cc>
</contact:addr>
</contact:postalInfo>
<contact:postalInfo type="loc">
<contact:name>'.htmlspecialchars($newdata["Contact Name"]).' </contact:name>
<contact:org>'.htmlspecialchars($newdata["Company Name"]).'</contact:org>
<contact:addr>
<contact:street>'.htmlspecialchars($newdata["Address 1"]).'</contact:street>

<contact:city>'.htmlspecialchars($newdata["City"]).'</contact:city>
<contact:sp>'.htmlspecialchars($newdata["State"]).'</contact:sp>
<contact:pc>'.$newdata["ZIP code"].'</contact:pc>
<contact:cc>'.$newdata["Country"].'</contact:cc>
</contact:addr>
</contact:postalInfo>
<contact:voice>'.$newdata["Phone"].'</contact:voice>
<contact:fax>'.$newdata["Fax"].'</contact:fax>
<contact:email>'.$newdata["Email"].'</contact:email>
</contact:chg>
</contact:update>
</update>
<extension>
<snic:contactInfo xmlns:snic="urn:saudinic:params:xml:ns:saudinic-1.0">
<snic:website>'.$newdata["Website"].'</snic:website>
<snic:mobile>'.$newdata["Mobile"].'</snic:mobile>
<snic:position>'.htmlspecialchars($newdata["Position"]).'</snic:position>
</snic:contactInfo>
</extension>
<clTRID>'.mt_rand().mt_rand().'</clTRID>
</command>
</epp>');
} else if (empty($newdata["Company Name"]) and !empty($newdata["Address 2"]) and !empty($newdata["Fax"])) {
    $result = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
<command>
<update>
<contact:update xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
<contact:id>'.$handle.'</contact:id>
<contact:chg>
<contact:postalInfo type="int">
<contact:name>'.htmlspecialchars($newdata["Contact Name"]).' </contact:name>

<contact:addr>
<contact:street>'.htmlspecialchars($newdata["Address 1"]).'</contact:street>
<contact:street>'.htmlspecialchars($newdata["Address 2"]).'</contact:street>
<contact:city>'.htmlspecialchars($newdata["City"]).'</contact:city>
<contact:sp>'.htmlspecialchars($newdata["State"]).'</contact:sp>
<contact:pc>'.$newdata["ZIP code"].'</contact:pc>
<contact:cc>'.$newdata["Country"].'</contact:cc>
</contact:addr>
</contact:postalInfo>
<contact:postalInfo type="loc">
<contact:name>'.htmlspecialchars($newdata["Contact Name"]).' </contact:name>

<contact:addr>
<contact:street>'.htmlspecialchars($newdata["Address 1"]).'</contact:street>
<contact:street>'.htmlspecialchars($newdata["Address 2"]).'</contact:street>
<contact:city>'.htmlspecialchars($newdata["City"]).'</contact:city>
<contact:sp>'.htmlspecialchars($newdata["State"]).'</contact:sp>
<contact:pc>'.$newdata["ZIP code"].'</contact:pc>
<contact:cc>'.$newdata["Country"].'</contact:cc>
</contact:addr>
</contact:postalInfo>
<contact:voice>'.$newdata["Phone"].'</contact:voice>
<contact:fax>'.$newdata["Fax"].'</contact:fax>
<contact:email>'.$newdata["Email"].'</contact:email>
</contact:chg>
</contact:update>
</update>
<extension>
<snic:contactInfo xmlns:snic="urn:saudinic:params:xml:ns:saudinic-1.0">
<snic:website>'.$newdata["Website"].'</snic:website>
<snic:mobile>'.$newdata["Mobile"].'</snic:mobile>
<snic:position>'.htmlspecialchars($newdata["Position"]).'</snic:position>
</snic:contactInfo>
</extension>
<clTRID>'.mt_rand().mt_rand().'</clTRID>
</command>
</epp>');
} else if (empty($newdata["Company Name"]) and empty($newdata["Address 2"]) and !empty($newdata["Fax"])) {
    $result = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
<command>
<update>
<contact:update xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
<contact:id>'.$handle.'</contact:id>
<contact:chg>
<contact:postalInfo type="int">
<contact:name>'.htmlspecialchars($newdata["Contact Name"]).' </contact:name>

<contact:addr>
<contact:street>'.htmlspecialchars($newdata["Address 1"]).'</contact:street>

<contact:city>'.htmlspecialchars($newdata["City"]).'</contact:city>
<contact:sp>'.htmlspecialchars($newdata["State"]).'</contact:sp>
<contact:pc>'.$newdata["ZIP code"].'</contact:pc>
<contact:cc>'.$newdata["Country"].'</contact:cc>
</contact:addr>
</contact:postalInfo>
<contact:postalInfo type="loc">
<contact:name>'.htmlspecialchars($newdata["Contact Name"]).' </contact:name>

<contact:addr>
<contact:street>'.htmlspecialchars($newdata["Address 1"]).'</contact:street>

<contact:city>'.htmlspecialchars($newdata["City"]).'</contact:city>
<contact:sp>'.htmlspecialchars($newdata["State"]).'</contact:sp>
<contact:pc>'.$newdata["ZIP code"].'</contact:pc>
<contact:cc>'.$newdata["Country"].'</contact:cc>
</contact:addr>
</contact:postalInfo>
<contact:voice>'.$newdata["Phone"].'</contact:voice>
<contact:fax>'.$newdata["Fax"].'</contact:fax>
<contact:email>'.$newdata["Email"].'</contact:email>
</contact:chg>
</contact:update>
</update>
<extension>
<snic:contactInfo xmlns:snic="urn:saudinic:params:xml:ns:saudinic-1.0">
<snic:website>'.$newdata["Website"].'</snic:website>
<snic:mobile>'.$newdata["Mobile"].'</snic:mobile>
<snic:position>'.htmlspecialchars($newdata["Position"]).'</snic:position>
</snic:contactInfo>
</extension>
<clTRID>'.mt_rand().mt_rand().'</clTRID>
</command>
</epp>');
} else if (empty($newdata["Company Name"]) and !empty($newdata["Address 2"]) and empty($newdata["Fax"])) {
    $result = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
<command>
<update>
<contact:update xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
<contact:id>'.$handle.'</contact:id>
<contact:chg>
<contact:postalInfo type="int">
<contact:name>'.htmlspecialchars($newdata["Contact Name"]).' </contact:name>

<contact:addr>
<contact:street>'.htmlspecialchars($newdata["Address 1"]).'</contact:street>
<contact:street>'.htmlspecialchars($newdata["Address 2"]).'</contact:street>
<contact:city>'.htmlspecialchars($newdata["City"]).'</contact:city>
<contact:sp>'.htmlspecialchars($newdata["State"]).'</contact:sp>
<contact:pc>'.$newdata["ZIP code"].'</contact:pc>
<contact:cc>'.$newdata["Country"].'</contact:cc>
</contact:addr>
</contact:postalInfo>
<contact:postalInfo type="loc">
<contact:name>'.htmlspecialchars($newdata["Contact Name"]).' </contact:name>

<contact:addr>
<contact:street>'.htmlspecialchars($newdata["Address 1"]).'</contact:street>
<contact:street>'.htmlspecialchars($newdata["Address 2"]).'</contact:street>
<contact:city>'.htmlspecialchars($newdata["City"]).'</contact:city>
<contact:sp>'.htmlspecialchars($newdata["State"]).'</contact:sp>
<contact:pc>'.$newdata["ZIP code"].'</contact:pc>
<contact:cc>'.$newdata["Country"].'</contact:cc>
</contact:addr>
</contact:postalInfo>
<contact:voice>'.$newdata["Phone"].'</contact:voice>

<contact:email>'.$newdata["Email"].'</contact:email>
</contact:chg>
</contact:update>
</update>
<extension>
<snic:contactInfo xmlns:snic="urn:saudinic:params:xml:ns:saudinic-1.0">
<snic:website>'.$newdata["Website"].'</snic:website>
<snic:mobile>'.$newdata["Mobile"].'</snic:mobile>
<snic:position>'.htmlspecialchars($newdata["Position"]).'</snic:position>
</snic:contactInfo>
</extension>
<clTRID>'.mt_rand().mt_rand().'</clTRID>
</command>
</epp>');
}


    # Parse XML result
    $doc = new DOMDocument();
    $doc->loadXML($result);
  /*  logModuleCall('COCCAepp', 'SaveContactDetails', $xml, $result); */
     HelperFunction::coccaepp_logModuleCall('COCCAepp','SaveContactDetails', $xml, $result);

    $coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
    $msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;
	if(!eppSuccess($coderes))
	{
        throw new Exception("contact-create($handle) $type: Code ($coderes) $msg");
	}
	
    return $handle;
}

# Function to get EPP Code
function COCCAepp_GetEPPCode($params)
{ 
	global $_LANG;
	# Grab variables
	$username = $params["Username"];
	$password = $params["Password"];
	$testmode = $params["TestMode"];
	$sld = $params["sld"];
	$tld = $params["tld"];
	$newEppKey = authKey();
	# Grab client instance
	try
	{
		$client = _COCCAepp_Client();

		# Register nameserver
		$request = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
												<epp xmlns="urn:ietf:params:xml:ns:epp-1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
													<command>
														<update>
															<domain:update xmlns:domain="urn:ietf:params:xml:ns:domain-1.0" xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
																<domain:name>'.$sld.'.'.$tld.'</domain:name>
																<domain:chg>
																	<domain:authInfo>
																		<domain:pw>' . $newEppKey . '</domain:pw>
																	</domain:authInfo>
																</domain:chg>
															</domain:update>
														</update>
														<clTRID>'  .mt_rand().mt_rand() . '</clTRID>
													</command>
												</epp>');
		# Parse XML result
		$doc= new DOMDocument();
		$doc->loadXML($request);
	/*	logModuleCall('COCCAepp', 'EPPCODE', $xml, $request); */
		HelperFunction::coccaepp_logModuleCall('COCCAepp','EPPCODE', $xml, $request);

		$values["eppcode"] = $newEppKey;
		
		# If error, return the error message in the value below
		$coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
		$msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;
		if(!eppSuccess($coderes))
		{
			$values["error"] = "Authcode/EPP($sld.$tld): Code ($coderes) $msg";
			return $values;
		}

		$values["status"] = $msg;
	/*	COCCAepp_GetEPPCode($params); */

                $get = DB::table('cocca_save_domain_contact')->where('domainid',$params["domainid"])->first();
                
                if($get->new_mobile_otp == 'verified' || $get->new_email_otp == 'verified')
                {
                    //request pending for admin contact chnage
                }
                else
                {
                    $getConatct =   COCCAepp_GetContactDetails($params);
                    $adminEmail =  $getConatct['Admin']['Email'];

                    $subject = $_LANG['domain_epp_code']. ' ' .$params["domain"];
                    $message = $_LANG['dear_administrator'].'<br/><br/>'.
				/*	$_LANG['please_find_below_the_epp_code_requested'].' ('.$params["domain"].') : '.$values["eppcode"]; */
			    	$_LANG['please_find_below_the_epp_code_requested'].' ('.$params["domain"].') : '.$_LANG['please_find_below_the_epp_code_requested2']. ' '  .$values["eppcode"];
				
                    include_once dirname(__FILE__) . '/mail.php';
                    $status = emailtouser($adminEmail, $subject, $message);
                    if($status)
                    {
                            logActivity("***--Domain EPP Code Email has been sent successfully.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
                            HelperFunction::coccaepp_approval_logModuleCall($params['domainname'], 'Send Email: EPP Code Email Sent', 'EPP Code Send','Email Sent Success');
                    }
                    else
                    {
                            logActivity("***--Domain EPP Code Email has been failed.Domain ID: {$params['domainid']} User ID: {$params['userid']}  --***");
                            HelperFunction::coccaepp_approval_logModuleCall($params['domainname'], 'Send Email Failed: EPP Code Email Sent failed', 'EPP Code Send','Email Sent Failed');
                    }
                    return $status;
                }
                
		
		// return $values;
	} 
	catch (Exception $e)
	{
		$values["error"] = 'Authcode/EPP: '.$e->getMessage();
		return $values;
	}
	// return $values;
}

# Function to register nameserver
function COCCAepp_RegisterNameserver($params)
{
	# Grab variables
	$username = $params["Username"];
	$password = $params["Password"];
	$testmode = $params["TestMode"];
	$sld = $params["sld"];
	$tld = $params["tld"];
	$nameserver = $params["nameserver"];
	$ipaddress = $params["ipaddress"];
	$domain = "$sld.$tld";

	# Grab client instance
	try
	{
		$client = _COCCAepp_Client();

		# Register nameserver
		$request = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
												<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
													<command>
														<create>
															<host:create xmlns:host="urn:ietf:params:xml:ns:host-1.0">
																<host:name>'.$nameserver.'</host:name>
																<host:addr ip="v4">'.$ipaddress.'</host:addr>
															</host:create>
														</create>
														<clTRID>'.mt_rand().mt_rand().'</clTRID>
													</command>
												</epp>');
		# Parse XML result
		$doc= new DOMDocument();
		$doc->loadXML($request);
	/*	logModuleCall('COCCAepp', 'RegisterNameserver', $xml, $request); */
			HelperFunction::coccaepp_logModuleCall('COCCAepp','RegisterNameserver', $xml, $request);

		# Pull off status
		$coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
		$msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;
	/*	logModuleCall('COCCAepp', 'SaveHost', $xml, $request); */
			HelperFunction::coccaepp_logModuleCall('COCCAepp','SaveHost', $xml, $request);
			
		# Check if result is ok
		if(!eppSuccess($coderes))
		{
			$values["error"] = "RegisterNameserver($nameserver): Code ($coderes) $msg";
			return $values;
		}

		$values['status'] = $msg;

	}
	catch (Exception $e)
	{
		$values["error"] = 'SaveNameservers/EPP: '.$e->getMessage();
		return $values;
	}
	return $values;
}

# Modify nameserver
function COCCAepp_ModifyNameserver($params)
{
	# Grab variables
	$username = $params["Username"];
	$password = $params["Password"];
	$testmode = $params["TestMode"];
	$tld = $params["tld"];
	$sld = $params["sld"];
	$nameserver = $params["nameserver"];
	$currentipaddress = $params["currentipaddress"];
	$newipaddress = $params["newipaddress"];
	$domain = "$sld.$tld";
	# Grab client instance
	try
	{
		$client = _COCCAepp_Client();

		# Modify nameserver
		$request = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
												<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
													<command>
														<update>
															<host:update xmlns:host="urn:ietf:params:xml:ns:host-1.0">
																<host:name>'.$nameserver.'</host:name>
																<host:add>
																	<host:addr ip="v4">'.$newipaddress.'</host:addr>
																</host:add>
																<host:rem>
																	<host:addr ip="v4">'.$currentipaddress.'</host:addr>
																</host:rem>
															</host:update>
														</update>
														<clTRID>'.mt_rand().mt_rand().'</clTRID>
													</command>
												</epp>');
		# Parse XML result
		$doc= new DOMDocument();
		$doc->loadXML($request);
	/*	logModuleCall('COCCAepp', 'ModifyNameserver', $xml, $request); */
			HelperFunction::coccaepp_logModuleCall('COCCAepp','ModifyNameserver', $xml, $request);

		# Pull off status
		$coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
		$msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;
		# Check if result is ok
		if(!eppSuccess($coderes))
		{
			$values["error"] = "ModifyNameserver/domain-update($nameserver): Code ($coderes) $msg";
			return $values;
		} 

		$values['status'] = $msg;

	}
	catch (Exception $e)
	{
		$values["error"] = 'SaveNameservers/EPP: '.$e->getMessage();
		return $values;
	}

	return $values;
}

# Delete nameserver
function COCCAepp_DeleteNameserver($params)
{
	# Grab variables
	$username = $params["Username"];
	$password = $params["Password"];
	$testmode = $params["TestMode"];
	$tld = $params["tld"];
	$sld = $params["sld"];
	$nameserver = $params["nameserver"];
	$domain = "$sld.$tld";
	
	# Grab client instance
	try
	{
		$client = _COCCAepp_Client();
		# Delete nameserver
		$request = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
												<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
													<command>
														<delete>
															<host:delete xmlns:host="urn:ietf:params:xml:ns:host-1.0">
																<host:name>'.$nameserver.'</host:name>
															</host:delete>
														</delete>
														<clTRID>'.mt_rand().mt_rand().'</clTRID>
													</command>
												</epp>');
		# Parse XML result
		$doc= new DOMDocument();
		$doc->loadXML($request);
	/*	logModuleCall('COCCAepp', 'DeleteNameserver', $xml, $request); */
			HelperFunction::coccaepp_logModuleCall('COCCAepp','DeleteNameserver', $xml, $request);
			

		# Pull off status
		$coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
		$msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;
		# Check if result is ok
		if(!eppSuccess($coderes))
		{
			$values["error"] = "DeleteNameserver/domain-update($sld.$tld): Code ($coderes) $msg";
			return $values;
		}
		$values['status'] = $msg;

	}
	catch (Exception $e)
	{
		$values["error"] = 'SaveNameservers/EPP: '.$e->getMessage();
		return $values;
	}
	return $values;
}

# Function to return meaningful message from response code
function _COCCAepp_message($code)
{
	return "Code $code";
}

# Function to create internal EPP request
function _COCCAepp_Client($params=null)
{
	# Setup include dir
	$include_path = ROOTDIR . '/modules/registrars/COCCAepp';
	set_include_path($include_path . PATH_SEPARATOR . get_include_path());
	# Include EPP stuff we need
	require_once 'Net/EPP/Client.php';
	require_once 'Net/EPP/Protocol.php';

	# Grab module parameters
	if(!$params)
	{
		$params = getregistrarconfigoptions('COCCAepp');
	}
	# Check if module parameters are sane
	if (empty($params['Username']) || empty($params['Password']))
	{
		throw new Exception('System configuration error(1), please contact your provider');
	}

    // Define some parameters
	$host= $params['Server'];
	$port= $params['Port'];

	//Get the EPP Configurations for the extension:

    # Create SSL context
	#$context = stream_context_create();
	$context = stream_context_create(['ssl' => [
													'verify_peer'      => false,
													'verify_peer_name' => false
												]
            						]);
	# Are we using ssl?
	$use_ssl = true;
	if (!empty($params['SSL']) && $params['SSL'] == 'on')
	{
		$use_ssl = true;
	}
	# Set certificate if we have one
	if ($use_ssl && !empty($params['Certificate']))
	{
		if (!file_exists($params['Certificate']))
		{
			throw new Exception("System configuration , please contact your provider");
		}
		# Set client side certificate
		stream_context_set_option($context, 'ssl', 'local_cert', $params['Certificate']);
	}

	# Create EPP client
	$client = new Net_EPP_Client();

	# Connect
	$res = $client->connect($host, $port, 60, $use_ssl, $context);

	# Perform login
	$request = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
											<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
												<command xmlns="urn:ietf:params:xml:ns:epp-1.0">
													<login>
														<clID>'.$params['Username'].'</clID>
														<pw>'.$params['Password'].'</pw>
														<options>
															<version>1.0</version>
															<lang>en</lang>
														</options>
														<svcs>
															<objURI>urn:ietf:params:xml:ns:domain-1.0</objURI>
															<objURI>urn:ietf:params:xml:ns:contact-1.0</objURI>
															<objURI>urn:ietf:params:xml:ns:host-1.0</objURI>
															<svcExtension>
																<extURI>urn:ietf:params:xml:ns:secDNS-1.1</extURI>
																<extURI>urn:saudinic:params:xml:ns:saudinic-1.0</extURI>
																<extURI>urn:ar:params:xml:ns:variant-1.1</extURI>
																<extURI>urn:ietf:params:xml:ns:rgp-1.0</extURI>
															</svcExtension>
														</svcs>
													</login>
													<clTRID>'.mt_rand().mt_rand().'</clTRID>
												</command>
											</epp>');
  	// logModuleCall('COCCAepp', 'Connect', $xml, $request);
  	return $client;
}

function COCCAepp_TransferSync($params)
{
	$domainid = $params['domainid'];
	$domain = $params['domain'];
	$sld = $params['sld'];
	$tld = $params['tld'];
	$registrar = $params['registrar'];
	$regperiod = $params['regperiod'];
	$status = $params['status'];
	$dnsmanagement = $params['dnsmanagement'];
	$emailforwarding = $params['emailforwarding'];
	$idprotection = $params['idprotection'];
	$domain = "$sld.$tld";
	# Other parameters used in your _getConfigArray() function would also be available for use in this function

	try
	{
		$client = _COCCAepp_Client();
		# Grab domain info
		$request = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
												<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
													<command>
														<info>
															<domain:info xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
																<domain:name hosts="all">'.$sld.'.'.$tld.'</domain:name>
															</domain:info>
														</info>
														<clTRID>'.mt_rand().mt_rand().'</clTRID>
													</command>
												</epp>');

		$doc= new DOMDocument();
		$doc->loadXML($request);
	/*	logModuleCall('COCCAepp', 'TransferSync', $xml, $request); */
			HelperFunction::coccaepp_logModuleCall('COCCAepp','TransferSync', $xml, $request);

		$coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
		$msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;
		# Check result
		if ($coderes == '2303')
		{
			$values['error'] = "TransferSync/domain-info($domain): Domain not found";
			return $values;
		}
		else if (!eppSuccess($coderes))
		{
			$values['error'] = "TransferSync/domain-info($domain): Code("._COCCAepp_message($coderes).") $msg";
			return $values;
		}

		# Check if we can get a status back
		if ($doc->getElementsByTagName('status')->item(0))
		{
			$statusres = $doc->getElementsByTagName('status')->item(0)->getAttribute('s');
			$createdate = substr($doc->getElementsByTagName('crDate')->item(0)->nodeValue,0,10);
			$nextduedate = substr($doc->getElementsByTagName('exDate')->item(0)->nodeValue,0,10);
		}
		else
		{
			$values['error'] = "TransferSync/domain-info($domain): Domain not found";
			return $values;
		}

		$values['status'] = $msg;

		# Check status and update
		if ($statusres == "ok" OR $statusres == "serverTransferProhibited")
		{
			$values['completed'] = true;
		}
		else
		{
			$values['error'] = "TransferSync/domain-info($domain): Unknown status code '$statusres'";
		}
		$values['expirydate'] = $nextduedate;

	}
	catch (Exception $e)
	{
		$values["error"] = 'TransferSync/EPP: '.$e->getMessage();
		return $values;
	}

	return $values;
}

// we can use COCCAepp_Sync script instaed of using this function
function COCCAepp_Sync($params)
{ 
	$sld = $params["sld"];
	$tld = $params["tld"];
	$domain = "$sld.$tld";
	# Get client instance

	# Let's Go...
	try
	{
		$isactive = true;
		$isexpired = false;
		$istransferredAway = false;
		$expireddomain = false;
		$errorMsg = '';
		if (!isset($client))
		{
			$client = _COCCAepp_Client();
		}
		# Grab domain info

		# Pull list of domains which are registered using this module
		# Loop with each one
		# Query domain

		$request = $client->request($xml='<?xml version="1.0" encoding="UTF-8" standalone="no"?>
												<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
													<command>
														<info>
															<domain:info xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
																<domain:name>'.$domain.'</domain:name>
															</domain:info>
														</info>
														<clTRID>'.mt_rand().mt_rand().'</clTRID>
													</command>
												</epp>');

		$doc= new DOMDocument();
		$doc->loadXML($request);
		$coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
		$resultpr =  $doc->getElementsByTagName('result')->item(0)->nodeValue;
		$msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;
		if (!eppSuccess($coderes))
		{
			$values["error"] = "Sync/domain-info('.$domain.'): Code (" . $coderes . ") " . $msg;
			return  array ('error' => $msg);

			$errorMsg =  $values["error"];
		}

		if($coderes == '1000')
		{
			if( $doc->getElementsByTagName('status'))
			{
				if($doc->getElementsByTagName('status')->item(0))
				{
					$statusres = $doc->getElementsByTagName('status')->item(0)->getAttribute('s');
					$createdate = substr($doc->getElementsByTagName('crDate')->item(0)->nodeValue,0,10);
					$nextduedate = substr($doc->getElementsByTagName('exDate')->item(0)->nodeValue,0,10);
					if((time()-(60*60*24)) > strtotime($nextduedate))
					{
						$expireddomain = true;
					}
				}
				else
				{
					$values["error"] = "Sync/domain-info('.$domain.'): Code (" . $coderes . ") " . $msg;
					$errorMsg = "Domain $domain not registered!";
					return  array ('error' => 'Domain $domain not registered!');
				}
				if($doc->getElementsByTagName('status')->item(1))
				{
					$statusres2 = $doc->getElementsByTagName('status')->item(1)->getAttribute('s');
				}
			}
		}
		else
		{
			$pendingStatus = 'Pending';
			if (strcmp($domainStatus, 'pending') == 0)
			{
			}
			elseif  ($resultpr == 'Object does not exist')
			{
				$values["error"] = "Sync/domain-info('.$domain.'): Code (" . $resultpr . ") " . $msg;
				return  array ('error' => 'Object does not exist');
			}
			elseif ($resultpr == 'Authorization error')
			{
				$istransferredAway = true;
				return  array (
				'transferredAway' => true, // Return true if the domain is transferred out
				'error' => 'Authorization error.'
				);
			}
			else
			{
			}
		}

		# This is the template we going to use below for our updates
		# Check status and update
		if ($statusres == "ok")
		{
			$isactive = true;
		}
		elseif($expireddomain == false && $statusres == "inactive" && $statusres2 == "serverHold")
		{
			$isactive = false;
		}
		elseif ($expireddomain == false && $statusres == "inactive" && $statusres2 != "serverHold")
		{
			$isactive = true;
		}
		elseif ($expireddomain == false && $statusres == "serverHold" && $statusres != "inactive")
		{
			$isactive = false;
		}
		elseif ( $statusres == "pendingCreate")
		{
			$isactive = false;
		}
		elseif ($statusres == "pendingDelete")
		{
			$isexpired = true;
		}
		elseif($statusres == "expired" || expireddomain == true )
		{
			$isexpired = true;
		}
		else
		{
			$isexpired = false;
		}
		return  array (
                    'expirydate' => $nextduedate, // Format: YYYY-MM-DD
                    'active' => (bool) $isactive , // Return true if the domain is active
                    'expired' => (bool) $isexpired, // Return true if the domain has expired
                    'transferredAway' => (bool) $istransferredAway, // Return true if the domain is transferred out
                 );
	}
	catch (Exception $e)
	{
        return array('error' => $e->getMessage(),);
	}
}

function COCCAepp_RequestDelete($params)
{
	$sld = $params['sld'];
	$tld = $params['tld'];
	$domain = "$sld.$tld";
	try
	{
		$client = _COCCAepp_Client();

		# Request Delete
		$request = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
												<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
													<command>
														<delete>
															<domain:delete xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
																<domain:name>'.$sld.'.'.$tld.'</domain:name>
															</domain:delete>
														</delete>
														<clTRID>'.mt_rand().mt_rand().'</clTRID>
													</command>
												</epp>');

		# Parse XML result
		$doc = new DOMDocument();
		$doc->loadXML($request);
	/*	logModuleCall('COCCAepp', 'RequestDelete', $xml, $request); */
		HelperFunction::coccaepp_logModuleCall('COCCAepp','RequestDelete', $xml, $request);

		$coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
		$msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;

		# Check result
		if(!eppSuccess($coderes))
		{
			$values['error'] = 'RequestDelete/domain-info('.$sld.'.'.$tld.'): Code('._COCCAepp_message($coderes).") $msg";
			return $values;
		}

		$values['status'] = $msg;

	}
	catch (Exception $e)
	{
		$values["error"] = 'RequestDelete/EPP: '.$e->getMessage();
		return $values;
	}
	return $values;

}

function COCCAepp_ApproveTransfer($params)
{
	$sld = $params['sld'];
	$tld = $params['tld'];
	$domain = "$sld.$tld"; 

	try
	{
		$client = _COCCAepp_Client();

		# Approve Transfer Request
		$request = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
												<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
													<command>
														<transfer op="approve">
															<domain:transfer xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
																<domain:name>'.$sld.'.'.$tld.'</domain:name>
															</domain:transfer>
														</transfer>
														<clTRID>'.mt_rand().mt_rand().'</clTRID>
													</command>
												</epp>');

		# Parse XML result
		$doc = new DOMDocument();
		$doc->loadXML($request);
	/*	logModuleCall('COCCAepp', 'ApproveTransfer', $xml, $request); */
			HelperFunction::coccaepp_logModuleCall('COCCAepp','ApproveTransfer', $xml, $request);

		$coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
		$msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;

		# Check result
		if(!eppSuccess($coderes))
		{
			$values['error'] = 'ApproveTransfer/domain-info('.$sld.'.'.$tld.'): Code('._COCCAepp_message($coderes).") $msg";
			return $values;
		}

		$values['status'] = $msg;

	}
	catch (Exception $e)
	{
		$values["error"] = 'ApproveTransfer/EPP: '.$e->getMessage();
		return $values;
	}

	return $values;
}

function COCCAepp_CancelTransferRequest($params)
{
	$sld = $params['sld'];
	$tld = $params['tld'];
	$domain = "$sld.$tld";
	try
	{
		$client = _COCCAepp_Client();

		# Cancel Transfer Request
		$request = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
												<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
													<command>
														<transfer op="cancel">
															<domain:transfer xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
																<domain:name>'.$sld.'.'.$tld.'</domain:name>
															</domain:transfer>
														</transfer>
														<clTRID>'.mt_rand().mt_rand().'</clTRID>
													</command>
												</epp>');

		# Parse XML result
		$doc = new DOMDocument();
		$doc->loadXML($request);
	/*	logModuleCall('COCCAepp', 'CancelTransferRequest', $xml, $request); */
			HelperFunction::coccaepp_logModuleCall('COCCAepp','CancelTransferRequest', $xml, $request);

		$coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
		$msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;

		# Check result
		if(!eppSuccess($coderes))
		{
			$values['error'] = 'CancelTransferRequest/domain-info('.$sld.'.'.$tld.'): Code('._COCCAepp_message($coderes).") $msg";
			return $values;
		} 
		$values['status'] = $msg;

	}
	catch (Exception $e)
	{
		$values["error"] = 'CancelTransferRequest/EPP: '.$e->getMessage();
		return $values;
	} 
	return $values;

}

function COCCAepp_RejectTransfer($params)
{
	$sld = $params['sld'];
	$tld = $params['tld'];
	$domain = "$sld.$tld";
	try
	{
		$client = _COCCAepp_Client();

		# Reject Transfer
		$request = $client->request($xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
												<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
													<command>
														<transfer op="reject">
															<domain:transfer xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
																<domain:name>'.$sld.'.'.$tld.'</domain:name>
															</domain:transfer>
														</transfer>
														<clTRID>'.mt_rand().mt_rand().'</clTRID>
													</command>
												</epp>');

		# Parse XML result
		$doc = new DOMDocument();
		$doc->loadXML($request);
	/*	logModuleCall('COCCAepp', 'RejectTransfer', $xml, $request); */
		HelperFunction::coccaepp_logModuleCall('COCCAepp','RejectTransfer', $xml, $request);

		$coderes = $doc->getElementsByTagName('result')->item(0)->getAttribute('code');
		$msg = $doc->getElementsByTagName('msg')->item(0)->nodeValue;

		# Check result
		if(!eppSuccess($coderes))
		{
			$values['error'] = 'RejectTransfer/domain-info('.$sld.'.'.$tld.'): Code('._COCCAepp_message($coderes).") $msg";
			return $values;
		}

		$values['status'] = $msg;

	}
	catch (Exception $e)
	{
		$values["error"] = 'RejectTransfer/EPP: '.$e->getMessage();
		return $values;
	}

	return $values;
}

function remove_locks($domain, $status)
{

}

function generateHandle()
{
    $stamp = time();
    $shuffled = str_shuffle("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ");
    $randStr = substr($shuffled, mt_rand(0, 45), 5);
    $handle = "$stamp$randStr";
    return $handle;
}

function array_push_assoc($array, $key, $value)
{
	$array[$key] = $value;
	return $array;
}

function eppSuccess($code)
{
	if ($code >= 1000 && $code < 2000)
	{
        return true;
    }
	return false;
}

function array_empty($a)
{
	foreach($a as $e)
	{
		if(!empty($e))
		{
			return false;
		}
	}
    return true;
}

function authKey($num=16)
{
    $chars = "a0Zb1Yc2Xd3We4Vf5Ug6Th7Si8Rj9Qk8Pl7Om6Nn5Mo4Lp3Kq2Jr1Is0Ht1Gu2Fv3Ew4Dx5Cy6Bz7A";
	$max = strlen($chars) - 1;
	$eppKey = null;
	$i = 0;
	while ($i < $num)
	{
		$eppKey .= $chars[mt_rand(0, $max)];
		++$i;
	}
	return $eppKey;
}

function getNID($domain)
{
    require_once dirname(__FILE__) . '/../../../init.php';
	
	$queryresult = mysql_query("SELECT  value  FROM tbldomainsadditionalfields WHERE domainid = (SELECT id FROM tbldomains WHERE domain = '" . $domain . "')");
	if (!$queryresult)
	{
    	die('Query failed: ' . mysql_error());
    }
    $temparray =  array();
    $getvalue=false;
	while($data = mysql_fetch_array($queryresult))
	{
		$dataValue=trim($data['value']);
		if ($dataValue == 'IND' || $dataValue =='ORG')
		{
            $additinaldata['Type']= $dataValue;
		}
		else
		{
			if (!empty($dataValue))
			{
                $additinaldata['Value']= $dataValue ;
                break;
            }
        }
    }
	return $additinaldata;
}
