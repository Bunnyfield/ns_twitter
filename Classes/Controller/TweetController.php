<?php
namespace Nitsan\NsTwitter\Controller;
/***************************************************************
*
*  Copyright notice
*
*  (c) 2017
*
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 3 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Messaging\AbstractMessage;
use TYPO3\CMS\Core\Utility\VersionNumberUtility;
use TYPO3\CMS\Core\Http\HttpRequest;
use Nitsan\NsTwitter\Contrib;
use TYPO3\CMS\Core\Http\RequestFactory;



/**
 * TweetController
 */
class TweetController extends \TYPO3\CMS\Extbase\Mvc\Controller\ActionController

{
	/**
	 * The base api url
	 *
	 * @var string
	 */
	protected $api_url = 'https://api.twitter.com/1.1/';

	/**
	 * action list
	 *
	 * @return void
	 */
	public function listAction()
	{
		$settings = $this->settings;
		$limit = empty($settings['limit']) ? 5 : $settings['limit'];
		$configuration = isset($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['ns_twitter']) ? unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['ns_twitter']) : '';

		$this->setConsumer($configuration['key'], $configuration['secret']);
		$this->setToken($configuration['authkey'], $configuration['authtoken']);

		/** Set access tokens here - see: https://dev.twitter.com/apps/ **/
		if ($configuration['key'] !== '' && $configuration['secret'] && $configuration['authkey'] && $configuration['authtoken']) {
			$url = "https://api.twitter.com/oauth2/token";
			$auth = base64_encode(urlencode($consumer['key']) . ':' . urlencode($consumer['secret']));
			$username = urlencode($settings['username']);
			try {
				if ($this->settings['mode'] == 'user') {
					$params['screen_name'] = array(
						'screen_name' => urlencode($this->settings['username'])
					);
					$path = 'statuses/user_timeline';
					$params['include_entities'] = 'true';
					if ($limit) {
						$params['count'] = $limit;
					}

					$response = $this->connectAPI($path, 'GET', $params, $limit);
					$tweets = json_decode($response->getBody() , 1);
				}
				else {
					$params = array(
						'q' => urlencode($this->settings['hashtag'])
					);
					$path = 'search/tweets';
					$params['include_entities'] = 'true';
					if ($limit) {
						$params['count'] = $limit;
					}

					$response = $this->connectAPI($path, 'GET', $params, $limit);
					$tweets = json_decode($response->getBody() , 1);
					$tweets = $tweets['statuses'];
				}

				if (isset($tweets['errors'])) {
					throw new \Exception('Error : ' . $tweets['errors']['0']['message']);
				}
				else {
					$result = '';
					foreach($tweets as $key => $value) {
						$results[$key] = $value;
						$createdDate = $value['created_at'];
						$text = $value['text'];
						if ($this->settings['dateFormat'] == 'ago') {
							$resultdate = $this->timeDifference($createdDate);
						}
						else {
							$resultdate = strtotime($createdDate);
						}

						$resulttext = $this->convert_links($text);
						$results[$key]['text'] = $resulttext;
						$results[$key]['created_at'] = $resultdate;
					}
					if(!empty($results)){
						$this->view->assign('tweets', $results);
					}
					else{
						if($this->settings['mode']=='user'){
							$args[] = $this->settings['username'];
						}
						else{
							$args[] = $this->settings['hashtag'];
						}
						$this->addFlashMessage(LocalizationUtility::translate('tweet.empty', 'ns_twitter',
						$args),'', \TYPO3\CMS\Core\Messaging\AbstractMessage::ERROR);
					}
				}
			}
			catch(\Exception $e) {
				$this->addFlashMessage($e->getMessage() , '', \TYPO3\CMS\Core\Messaging\AbstractMessage::ERROR);
			}
		}
		else {
			$this->addFlashMessage(LocalizationUtility::translate('outhError', 'ns_twitter') , '', \TYPO3\CMS\Core\Messaging\AbstractMessage::ERROR);
		}
	}

	/**
	 * Sets consumer based on key and secret
	 *
	 * @param string $key
	 * @param string $secret
	 * @return void
	 */
	public function setConsumer($key, $secret)
	{
		$this->consumer = GeneralUtility::makeInstance(\Nitsan\NsTwitter\Contrib\OAuthConsumer::class , $key, $secret);
	}

	/**
	 * Sets token based on key and secret
	 *
	 * @param string $key
	 * @param string $secret
	 * @return void
	 */
	public function setToken($key, $secret)
	{
		$this->token = GeneralUtility::makeInstance(\Nitsan\NsTwitter\Contrib\OAuthToken::class , $key, $secret);
	}

	public function connectAPI($path, $method, $params, $limit)
	{
		$version = GeneralUtility::makeInstance(VersionNumberUtility::class);

		$versionNum = $version->getNumericTypo3Version();
		$explode = explode(".", $versionNum);
		$request = \Nitsan\NsTwitter\Contrib\OAuthRequest::requestOauth($this->consumer, $this->token, 'GET', $this->api_url . $path . '.json', $params);
		$request->sendRequest(GeneralUtility::makeInstance(\Nitsan\NsTwitter\Contrib\OAuthSignatureMethod_HMAC_SHA1::class) , $this->consumer, $this->token);

		$url = $request->getUrl();
		$headers = get_headers($url);
		$method = 'GET';
		if(strpos($headers[0],'404') === false){
			if ($explode[0] == 7 || $explode[0] < 7) {

				// Http request for typo3 version 7 and lower than 7
				$request = GeneralUtility::makeInstance(HttpRequest::class , $url, $method);
				$response = $request->send();
				return $response;
			}
			else {
	            $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
	            // Return a PSR-7 compliant response object
	            $response = $requestFactory->request($url, 'GET');
	            if($response == False){

	            }
	            return $response;
			}
		}
		else{
			if($this->settings['mode']=='user'){
				$args[] = $this->settings['username'];
			}
			else{
				$args[] = $this->settings['hashtag'];
			}
			$errros = LocalizationUtility::translate('tweet.empty', 'ns_twitter', $args);
			throw new \Exception($errros);
		}
	}

	public function timeDifference($createdDate)
	{

		// get current timestampt
		$current = strtotime('now');

		// get timestamp when tweet created
		$createdDate = strtotime($createdDate);

		// get difference
		$difference = $current - $createdDate;

		// calculate different time values
		$minute = 60;
		$hour = $minute * 60;
		$day = $hour * 24;
		$week = $day * 7;
		if (is_numeric($difference) && $difference > 0) {

			// if less then 3 seconds
			if ($difference < 3) return LocalizationUtility::translate('rightnow', 'ns_twitter');

			// if less then minute
			if ($difference < $minute) return floor($difference) . LocalizationUtility::translate('seconds', 'ns_twitter');

			// if less then 2 minutes
			if ($difference < $minute * 2) return LocalizationUtility::translate('oneminute', 'ns_twitter');

			// if less then hour
			if ($difference < $hour) return floor($difference / $minute) . LocalizationUtility::translate('minute', 'ns_twitter');

			// if less then 2 hours
			if ($difference < $hour * 2) return LocalizationUtility::translate('onehour', 'ns_twitter');

			// if less then day
			if ($difference < $day) return floor($difference / $hour) . LocalizationUtility::translate('hours', 'ns_twitter');

			// if more then day, but less then 2 days
			if ($difference > $day && $difference < $day * 2) return LocalizationUtility::translate('yesterday', 'ns_twitter');

			// if less then year
			if ($difference < $day * 365) return floor($difference / $day) . LocalizationUtility::translate('days', 'ns_twitter');

			// else return more than a year
			return 'Over a year ago';
		}
	}

	public function convert_links($status, $targetBlank = true, $linkMaxLen = 250)
	{

		// the target
		$target = $targetBlank ? " target=\"_blank\" " : "";

		// convert link to url
		$status = preg_replace('/\b(https?|ftp|file):\/\/[-A-Z0-9+&@#\/%?=~_|!:,.;]*[A-Z0-9+&@#\/%=~_|]/i', '<a href="\0" target="_blank">\0</a>', $status);

		// convert @ to follow
		$status = preg_replace("/(@([_a-zA-Z0-9\-êàé-]+))/i", "<a href=\"https://twitter.com/$2\" title=\"Follow $2\" $target >$1</a>", $status);

		// convert # to search
		$status = preg_replace("/(#([_a-zA-Z0-9\-êàé-]+))/i", "<a href=\"https://twitter.com/search?q=$2\" title=\"Search $1\" $target >$1</a>", $status);

		// return the status
		return $status;
	}
}