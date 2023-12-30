<?php

namespace WHMCS\Module\Addon\DomainExpirationReminder;

use WHMCS\Module\Addon\DomainExpirationReminder\Models\Config;

class Cron
{
    static public function run()
    {
        $config     = Config::getAsArray();
        $domainList = Helpers::getDomainList($config["firstReminder"], $config["secondReminder"], $config["thirdReminder"]);
        $tldList    = [];

        foreach ($config["tlds"] as $tld)
        {
            $tldList[] = trim(trim($tld), ".");
        }

        foreach ($domainList as $domainData)
        {
            set_time_limit(120);//long loop

            $domainId   = $domainData["id"];
            $domainName = $domainData["domain"];
            $explode    = explode(".", $domainName, 2);
            $tld        = $explode[1];

            if(!in_array($tld, $tldList))
            {
                continue;
            }

            $domainModel = \WHMCS\Domain\Domain::find($domainId);

            if(!$domainModel)
            {
                continue;
            }

            $clientId    = $domainModel->userid;
            $clientModel = \WHMCS\User\Client::find($clientId);
            $to          = [];

            if($clientModel->email)
            {
                $to[] = $clientModel->email;
            }

            $registrar = false;

            try
            {
                $registrar = \WHMCS\Module\Registrar::factoryFromDomain($domainModel);
            }
            catch(\Exception $e)
            {
                logmodulecall("DomainExpirationReminder", "registrar error", $domainName, $e->getMessage());
            }

            if($registrar)
            {
                $response = $registrar->call("GetContactDetails");

                if($response['error'])
                {
                    logmodulecall("DomainExpirationReminder", "registrar error", $domainName, $response['error']);
                }
                else
                {
                    foreach ($response as $contactType => $contactData)
                    {
                        foreach ($contactData as $key => $value)
                        {
                            $email = trim($value);
                            if(filter_var($email, FILTER_VALIDATE_EMAIL) && !in_array($email, $to))
                            {
                                $to[] = $email;
                            }
                        }
                    }
                }
            }

            CustomMailer::sendEmail($config["emailTemplate"], $domainId, $to);
        }
    }
}
