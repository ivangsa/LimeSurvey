<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
    /*
    * LimeSurvey
    * Copyright (C) 2007-2011 The LimeSurvey Project Team / Carsten Schmitz
    * All rights reserved.
    * License: GNU/GPL License v2 or later, see LICENSE.php
    * LimeSurvey is free software. This version may have been modified pursuant
    * to the GNU General Public License, and as distributed it includes or
    * is derivative of works licensed under the GNU General Public License or
    * other free or open source software licenses.
    * See COPYRIGHT.php for copyright notices and details.
    *
    */

    /**
    * printanswers
    *
    * @package LimeSurvey
    * @copyright 2011
    * @access public
    */
    class PrintanswersController extends LSYii_Controller {



        /**
        * printanswers::view()
        * View answers at the end of a survey in one place. To export as pdf, set 'usepdfexport' = 1 in lsconfig.php and $printableexport='pdf'.
        * @param mixed $surveyid
        * @param bool $printableexport
        * @param int $id show the answers with this id 
        * @param string $token token for suveys
        * @return
        */
        function actionView($surveyid, $printableexport=FALSE, $id=NULL, $token=NULL)
        {
            Yii::app()->loadHelper("frontend");
            Yii::import('application.libraries.admin.pdf');

            $iSurveyID = (int)$surveyid;
            $sExportType = $printableexport;

            Yii::app()->loadHelper('database');
            
            // Get the survey inforamtion
            // Set the language for dispay
            if (isset($_SESSION['survey_'.$iSurveyID]['s_lang']))
            {
                $sLanguage = $_SESSION['survey_'.$iSurveyID]['s_lang'];
            }
            elseif(Survey::model()->findByPk($iSurveyID))// survey exist
            {
                $sLanguage = Survey::model()->findByPk($iSurveyID)->language;
            }
            else
            {
                $iSurveyID=0;
                $sLanguage = Yii::app()->getConfig("defaultlang");
            }
            SetSurveyLanguage($iSurveyID, $sLanguage);
            $aSurveyInfo = getSurveyInfo($iSurveyID,$sLanguage);
            $oTemplate = Template::model()->getInstance(null, $iSurveyID);
            if($oTemplate->cssFramework == 'bootstrap')
            {
                App()->bootstrap->register();
            }


            //Survey is not finished or don't exist
            if ($id==NULL && $token==NULL && (!isset($_SESSION['survey_'.$iSurveyID]['finished']) || !isset($_SESSION['survey_'.$iSurveyID]['srid'])))
            //display "sorry but your session has expired"
            {
                sendCacheHeaders();
                doHeader();

                /// $oTemplate is a global variable defined in controller/survey/index
                echo templatereplace(file_get_contents($oTemplate->viewPath.'/startpage.pstpl'),array());
                echo "<center><br />\n"
                ."\t<font color='RED'><strong>".gT("Error")."</strong></font><br />\n"
                ."\t".gT("We are sorry but your session has expired.")."<br />".gT("Either you have been inactive for too long, you have cookies disabled for your browser, or there were problems with your connection.")."<br />\n"
                ."\t".sprintf(gT("Please contact %s ( %s ) for further assistance."), Yii::app()->getConfig("siteadminname"), Yii::app()->getConfig("siteadminemail"))."\n"
                ."</center><br />\n";
                echo templatereplace(file_get_contents($oTemplate->viewPath.'/endpage.pstpl'),array());
                doFooter($iSurveyID);
                exit;
            }
            //Fin session time out

            //Ensure script is not run directly, avoid path disclosure
            //if (!isset($rootdir) || isset($_REQUEST['$rootdir'])) {die( "browse - Cannot run this script directly");}

            //Ensure Participants printAnswer setting is set to true or that the logged user have read permissions over the responses.
            if ($aSurveyInfo['printanswers'] == 'N' && !Permission::model()->hasSurveyPermission($iSurveyID,'responses','read'))
            {
                throw new CHttpException(401, gT('You are not allowed to print answers.'));
            }

            //CHECK IF SURVEY IS ACTIVATED AND EXISTS
            $sSurveyName = $aSurveyInfo['surveyls_title'];
            $sAnonymized = $aSurveyInfo['anonymized'];
            
            LimeExpressionManager::StartProcessingPage(true);  // means that all variables are on the same page
            LimeExpressionManager::StartProcessingGroup(1,($aSurveyInfo['anonymized']!="N"),$iSurveyID); // Since all data are loaded, and don't need JavaScript, pretend all from Group 1
            $printanswershonorsconditions = Yii::app()->getConfig('printanswershonorsconditions');
            
            $sSRID = $id;
            if($token!=NULL) {
                $aFullResponseTable = getFullResponseTableByToken($iSurveyID,'ivangsa@gmail.com',$sLanguage,$printanswershonorsconditions);
                $sSRID = $aFullResponseTable['id'][2];
            } else {
                if($sSRID==NULL){
                    $sSRID = $_SESSION['survey_'.$iSurveyID]['srid']; //I want to see the answers with this id
                }                
                $aFullResponseTable = getFullResponseTable($iSurveyID,$sSRID,$sLanguage,$printanswershonorsconditions);
            }
            traceVar($aFullResponseTable['id'][2]);
            
            //Get the fieldmap @TODO: do we need to filter out some fields?
            if($aSurveyInfo['datestamp']!="Y" || $sAnonymized == 'Y'){
                unset ($aFullResponseTable['submitdate']);
            }else{
                unset ($aFullResponseTable['id']);
            }
            unset ($aFullResponseTable['token']);
            unset ($aFullResponseTable['lastpage']);
            unset ($aFullResponseTable['startlanguage']);
            unset ($aFullResponseTable['datestamp']);
            unset ($aFullResponseTable['startdate']);     
            
            //OK. IF WE GOT THIS FAR, THEN THE SURVEY EXISTS AND IT IS ACTIVE, SO LETS GET TO WORK.
            //SHOW HEADER
             if($sExportType == 'pdf')
            {
                // Get images for TCPDF from template directory
                define('K_PATH_IMAGES', getTemplatePath($aSurveyInfo['template']).DIRECTORY_SEPARATOR);

                Yii::import('application.libraries.admin.pdf', true);
                Yii::import('application.helpers.pdfHelper');
                $aPdfLanguageSettings=pdfHelper::getPdfLanguageSettings(App()->language);

                $oPDF = new pdf();
                $sDefaultHeaderString = $sSurveyName." (".gT("ID",'unescaped').":".$iSurveyID.")";
                $oPDF->initAnswerPDF($aSurveyInfo, $aPdfLanguageSettings, Yii::app()->getConfig('sitename'), $sSurveyName, $sDefaultHeaderString);

                foreach ($aFullResponseTable as $sFieldname=>$fname)
                {
                    if (substr($sFieldname,0,4) == 'gid_')
                    {
                        $oPDF->addGidAnswer($fname[0], $fname[1]);
                    }
                    elseif ($sFieldname=='submitdate')
                    {
                        if($sAnonymized != 'Y')
                        {
                            $oPDF->addAnswer($fname[0]." ".$fname[1], $fname[2]);
                        }
                    }
                    elseif (substr($sFieldname,0,4) != 'qid_') // Question text is already in subquestion text, skipping it
                    {
                        $oPDF->addAnswer($fname[0]." ".$fname[1], $fname[2]);
                    }
                }

                header("Pragma: public");
                header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
                $sExportFileName = sanitize_filename($sSurveyName);
                $oPDF->Output($sExportFileName."-".$iSurveyID.".pdf","D");
            }
            elseif($sExportType == 'csv') {
                
//                viewHelper::disableHtmlLogging();
        foreach (App()->log->routes as $route)
        {
            $route->enabled = $route->enabled && !($route instanceOf CWebLogRoute);
        }

                $csvOut = '';
                $csvOut .= gT("Survey name (ID):")." ". $sSurveyName ." (".$iSurveyID.")\n";
                foreach ($aFullResponseTable as $sFieldname=>$fname)
                {
                    if (substr($sFieldname,0,4) == 'gid_')
                    {
                        $csvOut .= CSVEscape($fname[0])."\n";
                        $csvOut .= CSVEscape($fname[1])."\n";
                    }
                    elseif ($sFieldname=='submitdate')
                    {
                        if($sAnonymized != 'Y')
                        {
                            $csvOut .= "{$fname[0]} {$fname[1]},". $this->joinResponses($fname[3], ',')."\n";
                        }
                    }
                    elseif (substr($sFieldname,0,4) == 'qid_') // Question text is a subquestion
                    {
                        $csvOut .= "$fname[0]}\n";
                    }
                    else {
                        if($fname[1] != '') {
                            $csvOut .= CSVEscape($fname[1]).",".$this->joinResponses($fname[3], ',')."\n";
                        } else {
                            $csvOut .= CSVEscape($fname[0]).",".$this->joinResponses($fname[3], ',')."\n";
                        }
                    }
                }

                
                traceVar($csvOut);
                
                $sExportFileName = sanitize_filename($sSurveyName);
                
                
                header("Pragma: public");
                header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
                header("Content-Disposition: attachment; filename=" . $sExportFileName."-".$iSurveyID.".csv");
                header("Content-type: text/comma-separated-values; charset=UTF-8");                
                
                echo $csvOut;
                ob_flush();
            }
            else {
                $sOutput = "<div style='text-align: center;margin-bottom: 5px;'>";
                $sOutput .= CHtml::form(array("printanswers/view/surveyid/{$iSurveyID}/printableexport/pdf/id/{$sSRID}"), 'post', array("style"=>"display: inline-block;"))
                    ."<center><input class='btn btn-default' type='submit' value='".gT("PDF export")."'id=\"exportbutton\"/><input type='hidden' name='printableexport' /></center></form>";
                $sOutput .= "&nbsp;&nbsp;";
                $sOutput .= CHtml::form(array("printanswers/view/surveyid/{$iSurveyID}/printableexport/csv/id/{$sSRID}"), 'post', array("style"=>"display: inline-block;"))
                    ."<center><input class='btn btn-default' type='submit' value='".gT("Exportar a CSV")."'id=\"exportbutton\"/><input type='hidden' name='printableexport' /></center></form>";
                $sOutput .= "</div>";

                $sOutput .= "\t<div class='printouttitle'><strong>".gT("Survey name (ID):")."</strong> $sSurveyName ($iSurveyID)</div><p>&nbsp;\n";
                $sOutput .= "<table class='printouttable' >\n";
                foreach ($aFullResponseTable as $sFieldname=>$fname)
                {
                    if (substr($sFieldname,0,4) == 'gid_')
                    {
                        $sOutput .= "\t<tr class='printanswersgroup'><td colspan='10'>{$fname[0]}</td></tr>\n";
                        $sOutput .= "\t<tr class='printanswersgroupdesc'><td colspan='10'>{$fname[1]}</td></tr>\n";
                    }
                    elseif ($sFieldname=='submitdate')
                    {
                        if($sAnonymized != 'Y')
                        {
                            $sOutput .= "\t<tr class='printanswersquestion'><td>{$fname[0]} {$fname[1]}</td><td class='printanswersanswertext'>". $this->joinResponses($fname[3])."</td></tr>";
                        }
                    }
                    elseif (substr($sFieldname,0,4) == 'qid_') // Question text is a subquestion
                    {
                        $sOutput .= "\t<tr class='printanswersquestion'><td colspan='10'>{$fname[0]}</td></tr>";
                    }
                    else {
                        if($fname[1] != '') {
                            $sOutput .= "\t<tr class='printanswersquestion'><td>{$fname[1]}</td><td class='printanswersanswertext'>".$this->joinResponses($fname[3])."</td></tr>";
                        } else {
                            $sOutput .= "\t<tr class='printanswersquestion'><td>{$fname[0]}</td><td class='printanswersanswertext'>".$this->joinResponses($fname[3])."</td></tr>";
                        }
                    }
                }
                $sOutput .= "</table>\n";
                $sData['thissurvey']=$aSurveyInfo;
                $sOutput=templatereplace($sOutput, array() , $sData, '', $aSurveyInfo['anonymized']=="Y",NULL, array(), true);// Do a static replacement
                ob_start(function($buffer, $phase) {
                    App()->getClientScript()->render($buffer);
                    App()->getClientScript()->reset();
                    return $buffer;
                });
                ob_implicit_flush(false);

                sendCacheHeaders();
                doHeader();
                echo templatereplace(file_get_contents($oTemplate->viewPath.'/startpage.pstpl'),array(),$sData);
                echo templatereplace(file_get_contents($oTemplate->viewPath.'/printanswers.pstpl'),array('ANSWERTABLE'=>$sOutput),$sData);
                echo templatereplace(file_get_contents($oTemplate->viewPath.'/endpage.pstpl'),array(),$sData);
                echo "</body></html>";

                ob_flush();
            }

            LimeExpressionManager::FinishProcessingGroup();
            LimeExpressionManager::FinishProcessingPage();
        }

        /**
         * @param $expresion
         * @param $array
         */
        function joinResponses($values, $glue="</td><td class='printanswersanswertext'>"){
//            traceVar($values);
            foreach ($values as $i=>$value) {
                $values[$i] = flattenText($value);
            }
            return join($glue, $values);
        }

    }
