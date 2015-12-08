<?php

/**
 * @file mod/post.php
 *
 * @brief Zot endpoint.
 *
 */

require_once('include/zot.php');

/**
 * @brief HTTP POST entry point for Zot.
 *
 * Most access to this endpoint is via the post method.
 * Here we will pick out the magic auth params which arrive as a get request,
 * and the only communications to arrive this way.
 *
 * Magic Auth
 * ==========
 *
 * So-called "magic auth" takes place by a special exchange. On the site where the "channel to be authenticated" lives (e.g. $mysite), 
 * a redirection is made via $mysite/magic to the zot endpoint of the remote site ($remotesite) with special GET parameters.
 *
 * The endpoint is typically  https://$remotesite/post - or whatever was specified as the callback url in prior communications
 * (we will bootstrap an address and fetch a zot info packet if possible where no prior communications exist)
 *
 * Five GET parameters are supplied:
 * * auth => the urlencoded webbie (channel@host.domain) of the channel requesting access
 * * dest => the desired destination URL (urlencoded)
 * * sec  => a random string which is also stored on $mysite for use during the verification phase. 
 * * version => the zot revision
 * * delegate => optional urlencoded webbie of a local channel to invoke delegation rights for
 *
 * When this packet is received, an "auth-check" zot message is sent to $mysite.
 * (e.g. if $_GET['auth'] is foobar@podunk.edu, a zot packet is sent to the podunk.edu zot endpoint, which is typically /post)
 * If no information has been recorded about the requesting identity a zot information packet will be retrieved before
 * continuing.
 *
 * The sender of this packet is an arbitrary/random site channel. The recipients will be a single recipient corresponding
 * to the guid and guid_sig we have associated with the requesting auth identity
 *
 * \code{.json}
 * {
 *   "type":"auth_check",
 *   "sender":{
 *     "guid":"kgVFf_...",
 *     "guid_sig":"PT9-TApz...",
 *     "url":"http:\/\/podunk.edu",
 *     "url_sig":"T8Bp7j..."
 *   },
 *   "recipients":{
 *     {
 *       "guid":"ZHSqb...",
 *       "guid_sig":"JsAAXi..."
 *     }
 *   }
 *   "callback":"\/post",
 *   "version":1,
 *   "secret":"1eaa661",
 *   "secret_sig":"eKV968b1..."
 * }
 * \endcode
 *
 * auth_check messages MUST use encapsulated encryption. This message is sent to the origination site, which checks the 'secret' to see 
 * if it is the same as the 'sec' which it passed originally. It also checks the secret_sig which is the secret signed by the 
 * destination channel's private key and base64url encoded. If everything checks out, a json packet is returned:
 *
 * \code{.json}
 * {
 *   "success":1,
 *   "confirm":"q0Ysovd1u...",
 *   "service_class":(optional)
 *   "level":(optional)
 * }
 * \endcode
 *
 * 'confirm' in this case is the base64url encoded RSA signature of the concatenation of 'secret' with the
 * base64url encoded whirlpool hash of the requestor's guid and guid_sig; signed with the source channel private key. 
 * This prevents a man-in-the-middle from inserting a rogue success packet. Upon receipt and successful 
 * verification of this packet, the destination site will redirect to the original destination URL and indicate a successful remote login. 
 * Service_class can be used by cooperating sites to provide different access rights based on account rights and subscription plans. It is 
 * a string whose contents are not defined by protocol. Example: "basic" or "gold".
 *
 * @param[in,out] App &$a
 */
function post_init(&$a) {

	if (array_key_exists('auth', $_REQUEST)) {

		$ret = array('success' => false, 'message' => '');

		logger('mod_zot: auth request received.');
		$address  = $_REQUEST['auth'];
		$desturl  = $_REQUEST['dest'];
		$sec      = $_REQUEST['sec'];
		$version  = $_REQUEST['version'];
		$delegate = $_REQUEST['delegate'];

		$test     = ((x($_REQUEST, 'test')) ? intval($_REQUEST['test']) : 0);

		// They are authenticating ultimately to the site and not to a particular channel.
		// Any channel will do, providing it's currently active. We just need to have an 
		// identity to attach to the packet we send back. So find one. 

		$c = q("select * from channel where channel_removed = 0 limit 1");

		if (! $c) {
			// nobody here
			logger('mod_zot: auth: unable to find a response channel');
			if ($test) {
				$ret['message'] .= 'no local channels found.' . EOL;
				json_return_and_die($ret);
			}

			goaway($desturl);
		}

		// Try and find a hubloc for the person attempting to auth
		$x = q("select * from hubloc left join xchan on xchan_hash = hubloc_hash where hubloc_addr = '%s' order by hubloc_id desc",
			dbesc($address)
		);

		if (! $x) {
			// finger them if they can't be found. 
			$ret = zot_finger($address, null);
			if ($ret['success']) {
				$j = json_decode($ret['body'], true);
				if ($j)
					import_xchan($j);
				$x = q("select * from hubloc left join xchan on xchan_hash = hubloc_hash where hubloc_addr = '%s' order by hubloc_id desc",
					dbesc($address)
				);
			}
		}
		if(! $x) {
			logger('mod_zot: auth: unable to finger ' . $address);

			if($test) {
				$ret['message'] .= 'no hubloc found for ' . $address . ' and probing failed.' . EOL;
				json_return_and_die($ret);
			}

			goaway($desturl);
		}


		foreach($x as $xx) {
			logger('mod_zot: auth request received from ' . $xx['hubloc_addr'] ); 

			// check credentials and access

			// If they are already authenticated and haven't changed credentials, 
			// we can save an expensive network round trip and improve performance.

			$remote = remote_channel();
			$result = null;
			$remote_service_class = '';
			$remote_level = 0;
			$remote_hub = $xx['hubloc_url'];
			$DNT = 0;

			// Also check that they are coming from the same site as they authenticated with originally.

			$already_authed = ((($remote) && ($xx['hubloc_hash'] == $remote) && ($xx['hubloc_url'] === $_SESSION['remote_hub'])) ? true : false); 
			if($delegate && $delegate !== $_SESSION['delegate_channel'])
				$already_authed = false;

			$j = array();

			if (! $already_authed) {

				// Auth packets MUST use ultra top-secret hush-hush mode - e.g. the entire packet is encrypted using the site private key
				// The actual channel sending the packet ($c[0]) is not important, but this provides a generic zot packet with a sender
				// which can be verified
 
				$p = zot_build_packet($c[0],$type = 'auth_check', array(array('guid' => $xx['hubloc_guid'],'guid_sig' => $xx['hubloc_guid_sig'])), $xx['hubloc_sitekey'], $sec);
				if ($test) {
					$ret['message'] .= 'auth check packet created using sitekey ' . $xx['hubloc_sitekey'] . EOL;
					$ret['message'] .= 'packet contents: ' . $p . EOL;
				}

				$result = zot_zot($xx['hubloc_callback'],$p);

				if (! $result['success']) {
					logger('mod_zot: auth_check callback failed.');
					if ($test) {
						$ret['message'] .= 'auth check request to your site returned .' . print_r($result, true) . EOL;
						continue;
					}
					continue;
				}
				$j = json_decode($result['body'], true);
				if (! $j) {
					logger('mod_zot: auth_check json data malformed.');
					if($test) {
						$ret['message'] .= 'json malformed: ' . $result['body'] . EOL;
						continue;
					}
				}
			}

			if ($test) {
				$ret['message'] .= 'auth check request returned .' . print_r($j, true) . EOL;
			}

			if ($already_authed || $j['success']) {
				if ($j['success']) {
					// legit response, but we do need to check that this wasn't answered by a man-in-middle
					if (! rsa_verify($sec . $xx['xchan_hash'],base64url_decode($j['confirm']),$xx['xchan_pubkey'])) {
						logger('mod_zot: auth: final confirmation failed.');
						if ($test) {
							$ret['message'] .= 'final confirmation failed. ' . $sec . print_r($j,true) . print_r($xx,true);
							continue;
						}

						continue;
					}
					if (array_key_exists('service_class',$j))
						$remote_service_class = $j['service_class'];
					if (array_key_exists('level',$j))
						$remote_level = $j['level'];
					if (array_key_exists('DNT',$j))
						$DNT = $j['DNT'];
				}
				// everything is good... maybe
				if(local_channel()) {

					// tell them to logout if they're logged in locally as anything but the target remote account
					// in which case just shut up because they don't need to be doing this at all.

					if ($a->channel['channel_hash'] != $xx['xchan_hash']) {
						logger('mod_zot: auth: already authenticated locally as somebody else.');
						notice( t('Remote authentication blocked. You are logged into this site locally. Please logout and retry.') . EOL);
						if ($test) {
							$ret['message'] .= 'already logged in locally with a conflicting identity.' . EOL;
							continue;
						}
					}
					continue;
				}

				// log them in

				if ($test) {
					$ret['success'] = true;
					$ret['message'] .= 'Authentication Success!' . EOL;
					json_return_and_die($ret);
				}

				$delegation_success = false;
				if ($delegate) {
					$r = q("select * from channel left join xchan on channel_hash = xchan_hash where xchan_addr = '%s' limit 1",
						dbesc($delegate)
					);
					if ($r && intval($r[0]['channel_id'])) {
						$allowed = perm_is_allowed($r[0]['channel_id'],$xx['xchan_hash'],'delegate');
						if ($allowed) {
							$_SESSION['delegate_channel'] = $r[0]['channel_id'];
							$_SESSION['delegate'] = $xx['xchan_hash'];
							$_SESSION['account_id'] = intval($r[0]['channel_account_id']);
							require_once('include/security.php');
							change_channel($r[0]['channel_id']);
							$delegation_success = true;
						}
					}
				}

				$_SESSION['authenticated'] = 1;
				if (! $delegation_success) {
					$_SESSION['visitor_id'] = $xx['xchan_hash'];
					$_SESSION['my_url'] = $xx['xchan_url'];
					$_SESSION['my_address'] = $address;
					$_SESSION['remote_service_class'] = $remote_service_class;
					$_SESSION['remote_level'] = $remote_level;
					$_SESSION['remote_hub'] = $remote_hub;
					$_SESSION['DNT'] = $DNT;
				}

				$arr = array('xchan' => $xx, 'url' => $desturl, 'session' => $_SESSION);
				call_hooks('magic_auth_success',$arr);
				$a->set_observer($xx);
				require_once('include/security.php');
				$a->set_groups(init_groups_visitor($_SESSION['visitor_id']));
				info(sprintf( t('Welcome %s. Remote authentication successful.'),$xx['xchan_name']));
				logger('mod_zot: auth success from ' . $xx['xchan_addr']); 
			} 
			else {
				if ($test) {
					$ret['message'] .= 'auth failure. ' . print_r($_REQUEST,true) . print_r($j,true) . EOL;
					continue;
				}
				logger('mod_zot: magic-auth failure - not authenticated: ' . $xx['xchan_addr']);
			}

			if ($test) {
				$ret['message'] .= 'auth failure fallthrough ' . print_r($_REQUEST,true) . print_r($j,true) . EOL;
				continue;
			}
		}

		/**
		 * @FIXME we really want to save the return_url in the session before we
		 * visit rmagic. This does however prevent a recursion if you visit
		 * rmagic directly, as it would otherwise send you back here again.
		 * But z_root() probably isn't where you really want to go.
		 */

		if(strstr($desturl,z_root() . '/rmagic'))
			goaway(z_root());

		if ($test) {
			json_return_and_die($ret);
		}

		goaway($desturl);
	}
}


/**
 * @brief zot communications and messaging.
 *
 * Sender HTTP posts to this endpoint ($site/post typically) with 'data' parameter set to json zot message packet.
 * This packet is optionally encrypted, which we will discover if the json has an 'iv' element.
 * $contents => array( 'alg' => 'aes256cbc', 'iv' => initialisation vector, 'key' => decryption key, 'data' => encrypted data);
 * $contents->iv and $contents->key are random strings encrypted with this site's RSA public key and then base64url encoded.
 * Currently only 'aes256cbc' is used, but this is extensible should that algorithm prove inadequate.
 *
 * Once decrypted, one will find the normal json_encoded zot message packet.
 * 
 * Defined packet types are: notify, purge, refresh, force_refresh, auth_check, ping, and pickup 
 *
 * Standard packet: (used by notify, purge, refresh, force_refresh, and auth_check)
 * \code{.json}
 * {
 *   "type": "notify",
 *   "sender":{
 *     "guid":"kgVFf_1...",
 *     "guid_sig":"PT9-TApzp...",
 *     "url":"http:\/\/podunk.edu",
 *     "url_sig":"T8Bp7j5...",
 *   },
 *   "recipients": { optional recipient array },
 *   "callback":"\/post",
 *   "version":1,
 *   "secret":"1eaa...",
 *   "secret_sig": "df89025470fac8..."
 * }
 * \endcode
 *
 * Signature fields are all signed with the sender channel private key and base64url encoded.
 * Recipients are arrays of guid and guid_sig, which were previously signed with the recipients private 
 * key and base64url encoded and later obtained via channel discovery. Absence of recipients indicates
 * a public message or visible to all potential listeners on this site.
 *
 * "pickup" packet:
 * The pickup packet is sent in response to a notify packet from another site
 * \code{.json}
 * {
 *   "type":"pickup",
 *   "url":"http:\/\/example.com",
 *   "callback":"http:\/\/example.com\/post",
 *   "callback_sig":"teE1_fLI...",
 *   "secret":"1eaa...",
 *   "secret_sig":"O7nB4_..."
 * }
 * \endcode
 *
 * In the pickup packet, the sig fields correspond to the respective data
 * element signed with this site's system private key and then base64url encoded.
 * The "secret" is the same as the original secret from the notify packet. 
 *
 * If verification is successful, a json structure is returned containing a
 * success indicator and an array of type 'pickup'.
 * Each pickup element contains the original notify request and a message field
 * whose contents are dependent on the message type.
 *
 * This JSON array is AES encapsulated using the site public key of the site
 * that sent the initial zot pickup packet.
 * Using the above example, this would be example.com.
 *
 * \code{.json}
 * {
 *   "success":1,
 *   "pickup":{
 *     "notify":{
 *       "type":"notify",
 *       "sender":{
 *         "guid":"kgVFf_...",
 *         "guid_sig":"PT9-TApz...",
 *         "url":"http:\/\/z.podunk.edu",
 *         "url_sig":"T8Bp7j5D..."
 *       },
 *       "callback":"\/post",
 *       "version":1,
 *       "secret":"1eaa661..."
 *     },
 *     "message":{
 *       "type":"activity",
 *       "message_id":"10b049ce384cbb2da9467319bc98169ab36290b8bbb403aa0c0accd9cb072e76@podunk.edu",
 *       "message_top":"10b049ce384cbb2da9467319bc98169ab36290b8bbb403aa0c0accd9cb072e76@podunk.edu",
 *       "message_parent":"10b049ce384cbb2da9467319bc98169ab36290b8bbb403aa0c0accd9cb072e76@podunk.edu",
 *       "created":"2012-11-20 04:04:16",
 *       "edited":"2012-11-20 04:04:16",
 *       "title":"",
 *       "body":"Hi Nickordo",
 *       "app":"",
 *       "verb":"post",
 *       "object_type":"",
 *       "target_type":"",
 *       "permalink":"",
 *       "location":"",
 *       "longlat":"",
 *       "owner":{
 *         "name":"Indigo",
 *         "address":"indigo@podunk.edu",
 *         "url":"http:\/\/podunk.edu",
 *         "photo":{
 *           "mimetype":"image\/jpeg",
 *           "src":"http:\/\/podunk.edu\/photo\/profile\/m\/5"
 *         },
 *         "guid":"kgVFf_...",
 *         "guid_sig":"PT9-TAp...",
 *       },
 *       "author":{
 *         "name":"Indigo",
 *         "address":"indigo@podunk.edu",
 *         "url":"http:\/\/podunk.edu",
 *         "photo":{
 *           "mimetype":"image\/jpeg",
 *           "src":"http:\/\/podunk.edu\/photo\/profile\/m\/5"
 *         },
 *         "guid":"kgVFf_...",
 *         "guid_sig":"PT9-TAp..."
 *       }
 *     }
 *   }
 * }
 * \endcode
 *
 * Currently defined message types are 'activity', 'mail', 'profile', 'location'
 * and 'channel_sync', which each have different content schemas.
 *
 * Ping packet:
 * A ping packet does not require any parameters except the type. It may or may
 * not be encrypted.
 *
 * \code{.json}
 * {
 *   "type": "ping"
 * }
 * \endcode
 *
 * On receipt of a ping packet a ping response will be returned:
 *
 * \code{.json}
 * {
 *   "success" : 1,
 *   "site" {
 *     "url": "http:\/\/podunk.edu",
 *     "url_sig": "T8Bp7j5...",
 *     "sitekey": "-----BEGIN PUBLIC KEY-----
 *                 MIICIjANBgkqhkiG9w0BAQE..."
 *   }
 * }
 * \endcode
 *
 * The ping packet can be used to verify that a site has not been re-installed, and to 
 * initiate corrective action if it has. The url_sig is signed with the site private key
 * and base64url encoded - and this should verify with the enclosed sitekey. Failure to
 * verify indicates the site is corrupt or otherwise unable to communicate using zot.
 * This return packet is not otherwise verified, so should be compared with other
 * results obtained from this site which were verified prior to taking action. For instance
 * if you have one verified result with this signature and key, and other records for this 
 * url which have different signatures and keys, it indicates that the site was re-installed
 * and corrective action may commence (remove or mark invalid any entries with different
 * signatures).
 * If you have no records which match this url_sig and key - no corrective action should
 * be taken as this packet may have been returned by an imposter.  
 *
 * @param[in,out] App &$a
 */


function post_post(&$a) {

	require_once('Zotlabs/Zot/Receiver.php');
	require_once('Zotlabs/Zot/ZotHandler.php');

	$z = new Zotlabs\Zot\Receiver($_REQUEST['data'],get_config('system','prvkey'), new Zotlabs\Zot\ZotHandler());
	
	// notreached;

	exit;

}
