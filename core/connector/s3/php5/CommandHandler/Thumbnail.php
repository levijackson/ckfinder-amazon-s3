<?php
/*
* CKFinder
* ========
* http://cksource.com/ckfinder
* Copyright (C) 2007-2013, CKSource - Frederico Knabben. All rights reserved.
*
* The software, this file and its contents are subject to the CKFinder
* License. Please read the license.txt file before using, installing, copying,
* modifying or distribute this file or part of its contents. The contents of
* this file is part of the Source Code of CKFinder.
*/
if (!defined('IN_CKFINDER')) exit;

/**
 * @package CKFinder
 * @subpackage CommandHandlers
 * @copyright CKSource - Frederico Knabben
 */

/**
 * Handle Thumbnail command (create thumbnail if doesn't exist)
 *
 * @package CKFinder
 * @subpackage CommandHandlers
 * @copyright CKSource - Frederico Knabben
 */
class CKFinder_Connector_CommandHandler_Thumbnail extends CKFinder_Connector_CommandHandler_CommandHandlerBase
{
    /**
     * Command name
     *
     * @access private
     * @var string
     */
    private $command = "Thumbnail";

    /**
     * handle request and send response
     * @access public
     *
     */
    public function sendResponse()
    {
        // Get rid of BOM markers
        if (ob_get_level()) {
            while (@ob_end_clean() && ob_get_level());
        }
        header("Content-Encoding: none");

        $this->checkConnector();
        $this->checkRequest();

        global $config;
        $s3 = s3_con();

        $_config =& CKFinder_Connector_Core_Factory::getInstance("Core_Config");

        $_thumbnails = $_config->getThumbnailsConfig();
        if (!$_thumbnails->getIsEnabled()) {
            $this->_errorHandler->throwError(CKFINDER_CONNECTOR_ERROR_THUMBNAILS_DISABLED);
        }

        if (!$this->_currentFolder->checkAcl(CKFINDER_CONNECTOR_ACL_FILE_VIEW)) {
            $this->_errorHandler->throwError(CKFINDER_CONNECTOR_ERROR_UNAUTHORIZED);
        }

        if (!isset($_GET["FileName"])) {
            $this->_errorHandler->throwError(CKFINDER_CONNECTOR_ERROR_INVALID_REQUEST);
        }

        $fileName = CKFinder_Connector_Utils_FileSystem::convertToFilesystemEncoding($_GET["FileName"]);
        $_resourceTypeInfo = $this->_currentFolder->getResourceTypeConfig();

        if (!CKFinder_Connector_Utils_FileSystem::checkFileName($fileName)) {
            $this->_errorHandler->throwError(CKFINDER_CONNECTOR_ERROR_INVALID_REQUEST);
        }

        $sourceFilePath = ltrim(CKFinder_Connector_Utils_FileSystem::combinePaths($this->_currentFolder->getServerPath(), $fileName), '\/');

        if ($_resourceTypeInfo->checkIsHiddenFile($fileName) || !$s3->getObjectInfo($config['AmazonS3']['Bucket'], $sourceFilePath)) {
            $this->_errorHandler->throwError(CKFINDER_CONNECTOR_ERROR_FILE_NOT_FOUND);
        }

        $thumbFilePath = ltrim(CKFinder_Connector_Utils_FileSystem::combinePaths($this->_currentFolder->getThumbsServerPath(), $fileName), '\/');

        // If the thumbnail file doesn't exists, create it now.
        if (!$thumbInfo = $s3->getObjectInfo($config['AmazonS3']['Bucket'], $thumbFilePath)) {
            if(!$this->createThumb($sourceFilePath, $thumbFilePath, $_thumbnails->getMaxWidth(), $_thumbnails->getMaxHeight(), $_thumbnails->getQuality(), true, $_thumbnails->getBmpSupported())) {
                $this->_errorHandler->throwError(CKFINDER_CONNECTOR_ERROR_ACCESS_DENIED);
            }
        }

        $thumbFilePath = str_replace(' ', "%20", $config['AmazonS3']['baseURL'].$thumbFilePath);

        $ch = curl_init(); 
        curl_setopt_array($ch, array(
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_URL => $thumbFilePath,
            CURLOPT_RETURNTRANSFER => true

        ));
        $image = curl_exec($ch);

        $size = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        $mime = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $rtime = isset($_SERVER["HTTP_IF_MODIFIED_SINCE"])?@strtotime($_SERVER["HTTP_IF_MODIFIED_SINCE"]):0;
        $mtime =  curl_getinfo($ch, CURLOPT_FILETIME);
        $etag = dechex($mtime) . "-" . dechex($size);

        //header("Cache-Control: cache, must-revalidate");
        //header("Pragma: public");
        //header("Expires: 0");
        header('Cache-control: public');
        header('Etag: ' . $etag);
        header("Content-type: " . $mime . "; name=\"" . CKFinder_Connector_Utils_Misc::mbBasename($thumbFilePath) . "\"");
        header("Last-Modified: ".gmdate('D, d M Y H:i:s', $mtime) . " GMT");
        //header("Content-type: application/octet-stream; name=\"{$file}\"");
        //header("Content-Disposition: attachment; filename=\"{$file}\"");
        header("Content-Length: ".$size);
        echo $image; 

        exit;
    }

    /**
     * Create thumbnail
     *
     * @param string $sourceFile
     * @param string $targetFile
     * @param int $maxWidth
     * @param int $maxHeight
     * @param boolean $preserverAspectRatio
     * @param boolean $bmpSupported
     * @return boolean
     * @static
     * @access public
     */
    public static function createThumb($sourceFile, $targetFile, $maxWidth, $maxHeight, $quality, $preserverAspectRatio, $bmpSupported = false)
    {
        global $config;
        $s3 = s3_con();

        $fileURL = str_replace(' ', "%20", $config['AmazonS3']['baseURL'].$sourceFile);
        $image = $s3->getObject($config['AmazonS3']['Bucket'], $sourceFile);

        $sourceFile = imagecreatefromstring($image->body);
        $sourceImageAttr = getimagesize($fileURL);

        if ($sourceImageAttr === false) {
            return false;
        }

        $sourceImageWidth = isset($sourceImageAttr[0]) ? $sourceImageAttr[0] : 0;
        $sourceImageHeight = isset($sourceImageAttr[1]) ? $sourceImageAttr[1] : 0;
        $sourceImageMime = isset($sourceImageAttr["mime"]) ? $sourceImageAttr["mime"] : "";
        $sourceImageBits = isset($sourceImageAttr["bits"]) ? $sourceImageAttr["bits"] : 8;
        $sourceImageChannels = isset($sourceImageAttr["channels"]) ? $sourceImageAttr["channels"] : 3;

        if (!$sourceImageWidth || !$sourceImageHeight || !$sourceImageMime) {
            return false;
        }

        $iFinalWidth = $maxWidth == 0 ? $sourceImageWidth : $maxWidth;
        $iFinalHeight = $maxHeight == 0 ? $sourceImageHeight : $maxHeight;

        if ($sourceImageWidth <= $iFinalWidth && $sourceImageHeight <= $iFinalHeight) {
            if ($sourceFile != $targetFile) {
                $s3->putObject($sourceFile, $config['AmazonS3']['Bucket'], $targetFile);
            }
            return true;
        }

        if ($preserverAspectRatio)
        {
            // Gets the best size for aspect ratio resampling
            $oSize = CKFinder_Connector_CommandHandler_Thumbnail::GetAspectRatioSize($iFinalWidth, $iFinalHeight, $sourceImageWidth, $sourceImageHeight );
        }
        else {
            $oSize = array('Width' => $iFinalWidth, 'Height' => $iFinalHeight);
        }

        CKFinder_Connector_Utils_Misc::setMemoryForImage($sourceImageWidth, $sourceImageHeight, $sourceImageBits, $sourceImageChannels);

        $thumbImage['type'] = $sourceImageAttr['mime'];
        switch ($sourceImageAttr['mime'])
        {
            case 'image/gif':
                {
                    if (@imagetypes() & IMG_GIF) {
                        $oImage = $sourceFile;
                    } else {
                        $ermsg = 'GIF images are not supported';
                    }
                }
                break;
            case 'image/jpeg':
                {
                    if (@imagetypes() & IMG_JPG) {
                        $oImage = $sourceFile;
                    } else {
                        $ermsg = 'JPEG images are not supported';
                    }
                }
                break;
            case 'image/png':
                {
                    if (@imagetypes() & IMG_PNG) {
                        $oImage = $sourceFile;
                    } else {
                        $ermsg = 'PNG images are not supported';
                    }
                }
                break;
            case 'image/wbmp':
                {
                    if (@imagetypes() & IMG_WBMP) {
                        $oImage = $sourceFile;
                    } else {
                        $ermsg = 'WBMP images are not supported';
                    }
                }
                break;
            default:
                $ermsg = $sourceImageAttr['mime'].' images are not supported';
                break;
        }

        

        if (isset($ermsg) || false === $oImage) {
            return false;
        }


        $oThumbImage = imagecreatetruecolor($oSize["Width"], $oSize["Height"]);

        if ($sourceImageAttr['mime'] == 'image/png')
        {
            $bg = imagecolorallocatealpha($oThumbImage, 255, 255, 255, 127); // (PHP 4 >= 4.3.2, PHP 5)
            imagefill($oThumbImage, 0, 0 , $bg);
            imagealphablending($oThumbImage, false);
            imagesavealpha($oThumbImage, true);
        }

        imagecopyresampled($oThumbImage, $oImage, 0, 0, 0, 0, $oSize["Width"], $oSize["Height"], $sourceImageWidth, $sourceImageHeight);

        ob_start();
        switch ($sourceImageAttr['mime'])
        {
            case 'image/gif':
                imagegif($oThumbImage);
                break;
            case 'image/jpeg':
            case 'image/bmp':
                imagejpeg($oThumbImage);
                break;
            case 'image/png':
                imagepng($oThumbImage);
                break;
            case 'image/wbmp':
                imagewbmp($oThumbImage);
                break;
        }
        $tmpImage = ob_get_clean();
        $thumbImage['data'] = $tmpImage;

        $s3->putObject($thumbImage, $config['AmazonS3']['Bucket'], $targetFile, s3::ACL_PUBLIC_READ);
        imageDestroy($oImage);
        imageDestroy($oThumbImage);

        return true;
    }



    /**
     * Return aspect ratio size, returns associative array:
     * <pre>
     * Array
     * (
     *      [Width] => 80
     *      [Heigth] => 120
     * )
     * </pre>
     *
     * @param int $maxWidth
     * @param int $maxHeight
     * @param int $actualWidth
     * @param int $actualHeight
     * @return array
     * @static
     * @access public
     */
    public static function getAspectRatioSize($maxWidth, $maxHeight, $actualWidth, $actualHeight)
    {
        $oSize = array("Width"=>$maxWidth, "Height"=>$maxHeight);

        // Calculates the X and Y resize factors
        $iFactorX = (float)$maxWidth / (float)$actualWidth;
        $iFactorY = (float)$maxHeight / (float)$actualHeight;

        // If some dimension have to be scaled
        if ($iFactorX != 1 || $iFactorY != 1)
        {
            // Uses the lower Factor to scale the oposite size
            if ($iFactorX < $iFactorY) {
                $oSize["Height"] = (int)round($actualHeight * $iFactorX);
            }
            else if ($iFactorX > $iFactorY) {
                $oSize["Width"] = (int)round($actualWidth * $iFactorY);
            }
        }

        if ($oSize["Height"] <= 0) {
            $oSize["Height"] = 1;
        }
        if ($oSize["Width"] <= 0) {
            $oSize["Width"] = 1;
        }

        // Returns the Size
        return $oSize;
    }
}
