<?php

/**
 * Include the Social Library
 */
function oa_social_login_add_javascripts ()
{
	//Read the plugin settings.
	$settings = get_option ('oa_social_login_settings');

	//Without the subdomain we can't include the libary.
	if (!empty ($settings ['api_subdomain']))
	{
		//Forge library path.
		$oneall_js_library = ((oa_social_login_https_on () ? 'https' : 'http') . '://' . $settings ['api_subdomain'] . '.api.oneall.com/socialize/library.js');

		// Synchronous JavaScript: This is the default to stay compatible with existing installations.
		if (empty ($settings ['asynchronous_javascript']))
		{
			//Make sure the library has not yet been included.
			if (!wp_script_is ('oa_social_library', 'registered'))
			{
				//Include in header, without having the version appended
				wp_register_script ('oa_social_library', $oneall_js_library, array (), null, false);
			}
			wp_print_scripts ('oa_social_library');
		}
		// Asynchronous JavaScript.
		else
		{
			//JavaScript Method Reference: http://docs.oneall.com/api/javascript/library/methods/
			$output = array ();
			$output [] = '';
			$output [] = " <!-- OneAll.com / Social Login for WordPress / v" . constant ('OA_SOCIAL_LOGIN_VERSION') . " -->";
			$output [] = '<script type="text/javascript">';
			$output [] = " (function() {";
			$output [] = "  var oa = document.createElement('script'); oa.type = 'text/javascript';";
			$output [] = "  oa.async = true; oa.src = '" . $oneall_js_library . "';";
			$output [] = "  var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(oa, s);";
			$output [] = " })();";
			$output [] = "</script>";
			$output [] = '';

			//Display
			echo implode ("\n", $output);
		}
	}
}

//This is for Social Login
add_action ('login_head', 'oa_social_login_add_javascripts');
add_action ('wp_head', 'oa_social_login_add_javascripts');

//This is for Social Link
add_action ('show_user_profile', 'oa_social_login_add_javascripts');

/**
 * **************************************************************************************************************
 * ************************************************ SOCIAL LINK *************************************************
 * **************************************************************************************************************
 */


/**
 * Render the Social Link form for the given user
 */
function oa_social_login_render_link_form ($source, $user)
{
	//Store the data being returned.
	$output = '';

	if (is_object ($user) AND property_exists ($user, 'data') AND !empty ($user->data->ID))
	{
		//Only show Social Link if the user is viewing his own profile.
		if ($user->data->ID == get_current_user_id ())
		{
			//Identifier of the current user.
			$userid = $user->data->ID;

			//Read Social Login settings.
			$settings = get_option ('oa_social_login_settings');

			//Is Social Link enabled?
			if (!isset ($settings ['plugin_profile_show']) OR !empty ($settings ['plugin_profile_show']))
			{
				//Parse API Settings.
				$api_connection_handler = ((!empty ($settings ['api_connection_handler']) AND $settings ['api_connection_handler'] == 'fsockopen') ? 'fsockopen' : 'curl');
				$api_connection_use_https = ((!isset ($settings ['api_connection_use_https']) OR $settings ['api_connection_use_https'] == '1') ? true : false);
				$api_subdomain = (!empty ($settings ['api_subdomain']) ? $settings ['api_subdomain'] : '');
				$api_key = (!empty ($settings ['api_key']) ? $settings ['api_key'] : '');
				$api_secret = (!empty ($settings ['api_secret']) ? $settings ['api_secret'] : '');

				//Import the available social networks.
				GLOBAL $oa_social_login_providers;

				//Setup the selected social networks.
				$api_providers = array ();
				if (is_array ($settings ['providers']))
				{
					foreach ($settings ['providers'] AS $settings_provider_key => $settings_provider_name)
					{
						if (isset ($oa_social_login_providers [$settings_provider_key]))
						{
							$api_providers [] = $settings_provider_key;
						}
					}
				}

				//The API subdomain is required.
				if (!empty ($api_subdomain))
				{
					//No providers have been selected.
					if (count ($api_providers) == 0)
					{
						$output = '<div style="color:white;background-color:red;">[Social Login] ' . __ ('Please enable at least one social network!', 'oa_social_login') . '</div>';
					}
					//At least one providers has been selected.
					else
					{
						//Message Storage.
						$error_message = '';
						$success_message = '';

						//Callback Handler.
						if (isset ($_POST) AND !empty ($_POST ['oa_action']) AND $_POST ['oa_action'] == 'social_link' AND !empty ($_POST ['connection_token']))
						{
							//More info here: https://docs.oneall.com/api/resources/connections/read-connection-details/
							$api_resource_url = ($api_connection_use_https ? 'https' : 'http') . '://' . $api_subdomain . '.api.oneall.com/connections/' . $_POST ['connection_token'] . '.json';

							//Get connection details
							$result = oa_social_login_do_api_request ($api_connection_handler, $api_resource_url, array ('api_key' => $api_key, 'api_secret' => $api_secret));

							//Parse result
							if (is_object ($result) AND property_exists ($result, 'http_code') AND property_exists ($result, 'http_data') AND $result->http_code == 200)
							{
								//Decode
								$json_decoded = json_decode ($result->http_data);

								//User Data
								if (is_object ($json_decoded) AND property_exists ($json_decoded, 'response'))
								{
									//Extract data
									$data = $json_decoded->response->result->data;

									//Check for plugin status
									if (is_object ($data) AND property_exists ($data, 'plugin') AND $data->plugin->key == 'social_link' AND $data->plugin->data->status == 'success')
									{
										//Get the id of the linked user - Can be empty
										$userid_by_token = oa_social_login_get_userid_by_token ($data->user->user_token);

										//Link identity
										if ($data->plugin->data->action == 'link_identity')
										{
											// The user already has a user_token
											if (is_numeric ($userid_by_token))
											{
												//Already connected to this user
												if ($userid_by_token == $userid)
												{
													$success_message = sprintf (__ ('You have successfully linked your %s account.', 'oa_social_login'), $data->user->identity->source->name);

													//Read provider list
													$meta_identity_providers = trim (strval (get_user_meta ($userid, 'oa_social_login_identity_provider', true)));
													$meta_identity_providers = explode ("|", $meta_identity_providers);

													//Update new provider list
													$identity_providers = array (
														trim ($data->user->identity->source->name)
													);
													foreach ($meta_identity_providers AS $meta_identity_provider)
													{
														if (strlen (trim ($meta_identity_provider)) > 0)
														{
															$identity_providers [] = trim ($meta_identity_provider);
														}
													}

													//First Provider
													if (count ($identity_providers) == 1)
													{
														update_user_meta ($userid, 'oa_social_login_identity_provider', array_shift ($identity_providers));
													}
													//Multiple Providers
													else
													{
														update_user_meta ($userid, 'oa_social_login_identity_provider', implode ("|", $identity_providers));
													}
												}
												//Connected to a different user
												else
												{
													$error_message = sprintf (__ ('This %s account is already used by another user of this website.', 'oa_social_login'), $data->user->identity->source->name);
												}
											}
											// The user does not have a user_token yet
											else
											{
												$success_message = sprintf (__ ('You have successfully linked your %s account.', 'oa_social_login'), $data->user->identity->source->name);

												//Clean Cache
												wp_cache_delete ($userid, 'users');

												//User Meta Data
												update_user_meta ($userid, 'oa_social_login_user_token', $data->user->user_token);
												update_user_meta ($userid, 'oa_social_login_identity_provider', $data->user->identity->source->name);

												//Thumbnail
												if (!empty ($data->user->identity->thumbnailUrl))
												{
													update_user_meta ($userid, 'oa_social_login_user_thumbnail', $data->user->identity->thumbnailUrl);
												}
											}
										}
										//UnLink identity
										elseif ($data->plugin->data->action == 'unlink_identity')
										{
											// The user already has a user_token
											if (is_numeric ($userid_by_token))
											{
												//Was connected to this user
												if ($userid_by_token == $userid)
												{
													$success_message = sprintf (__ ('You have successfully unlinked your %s account.', 'oa_social_login'), $data->user->identity->source->name);

													//Read provider list
													$meta_identity_providers = trim (strval (get_user_meta ($userid, 'oa_social_login_identity_provider', true)));
													$meta_identity_providers = explode ("|", $meta_identity_providers);

													//Update new provider list
													$identity_providers = array ();
													$discard_existing_identity_provider = true;
													foreach ($meta_identity_providers AS $meta_identity_provider)
													{
														if (strlen (trim ($meta_identity_provider)) > 0)
														{
															$meta_identity_provider = trim ($meta_identity_provider);

															//Different from the one that has been unlinked
															if ($meta_identity_provider <> $data->user->identity->source->name)
															{
																$identity_providers [] = $meta_identity_provider;
															}
															//The same as the one that has been unlinked
															else
															{
																//Only discard the first
																if ($discard_existing_identity_provider)
																{
																	$discard_existing_identity_provider = false;
																}
																//Keep the others
																else
																{
																	$identity_providers [] = $meta_identity_provider;
																}
															}
														}
													}

													//One provider linked
													if (count ($identity_providers) == 1)
													{
														update_user_meta ($userid, 'oa_social_login_identity_provider', array_shift ($identity_providers));
													}
													//Zero, two or more
													else
													{
														//No providers linked
														if (count ($identity_providers) == 0)
														{
															$error_message = __ ("You might no longer be able to login to this website if you don't link at least one social network.", 'oa_social_login');
															update_user_meta ($userid, 'oa_social_login_identity_provider', '');
														}
														else
														{
															update_user_meta ($userid, 'oa_social_login_identity_provider', implode ("|", $identity_providers));
														}
													}
												}
												//Connected to a different user
												else
												{
													$error_message = sprintf (__ ('This %s account is already used by another user of this website.', 'oa_social_login'), $data->user->identity->source->name);
												}
											}
											// The user does not have a user_token yet
											else
											{
												//Nothing to do
											}
										}
									}
								}
							}
						}

						//Custom CSS
						$css_theme_uri = apply_filters ('oa_social_login_link_css', $css_theme_uri);

						//OneAll user_token
						$token = strval (oa_social_login_get_token_by_userid ($userid));

						//Random integer
						$rand = mt_rand (99999, 9999999);

						//Callback URI
						$callback_uri = oa_social_login_get_current_url ();
						$callback_uri .= (strlen (parse_url ($callback_uri, PHP_URL_QUERY)) == 0 ? '?' : '&') . 'oa_social_login_source=' . $source . '#oa_social_link';

						//Setup Social Container
						$containerid = 'oneall_social_login_providers_' . mt_rand (99999, 9999999);


						//Setup Social Link
						$social_link = array ();
						$social_link [] = '<div class="oneall_social_link">';
						$social_link [] = ' <div class="oneall_social_login_providers" id="' . $containerid . '"></div>';
						$social_link [] = ' <script type="text/javascript">';

						//Synchronous JavaScript: This is the default to stay compatible with existing installations.
						if (empty ($settings ['asynchronous_javascript']))
						{
							$social_link [] = '  oneall.api.plugins.social_link.build("' . $containerid . '", {';
							$social_link [] = '   "providers": ["' . implode ('","', $api_providers) . '"], ';
							$social_link [] = '   "user_token": "' . $token . '", ';
							$social_link [] = '   "callback_uri": "' . $callback_uri . '", ';
							$social_link [] = '  });';
						}
						//Asynchronous JavaScript.
						else
						{
							//JavaScript Method Reference: http://docs.oneall.com/api/javascript/library/methods/
							$social_link [] = "  var _oneall = _oneall || [];";
							$social_link [] = "  _oneall.push(['social_link', 'set_providers', ['" . implode ("','", $api_providers) . "']]);";
							$social_link [] = "  _oneall.push(['social_link', 'set_user_token', '" . $token . "']);";
							$social_link [] = "  _oneall.push(['social_link', 'set_callback_uri', '" . $callback_uri . "']);";
							$social_link [] = "  _oneall.push(['social_link', 'set_custom_css_uri', '" . $css_theme_uri . "']);";
							$social_link [] = "  _oneall.push(['social_link', 'do_render_ui', '" . $containerid . "']);";
						}

						$social_link [] = " </script>";
						$social_link [] = '</div>';
						$social_link = implode ("\n", $social_link);


						//Setup Output
						$output .= '<h3 id="oa_social_link"> ' . __ ('Connect your account to one or more social networks', 'oa_social_login') . '</h3>';
						$output .= '<table class="form-table">';
						$output .= (empty ($success_message) ? '' : '<tr><td><span style="color:green;font-weight:bold"> ' . $success_message . '</span></td></tr>');
						$output .= (empty ($error_message) ? '' : '<tr><td><span style="color:red;font-weight:bold">' . $error_message . '</span></td></tr>');
						$output .= '<tr><td>' . $social_link . '</td></tr>';
						$output .= '</table>';
					}
				}
			}
		}
	}
	return $output;
}


/**
 * Add Social Link to the user's profile
 */
function oa_social_login_add_social_link ($user)
{
	echo oa_social_login_render_link_form ('profile', $user);
}
add_action ('show_user_profile', 'oa_social_login_add_social_link');


/**
 * Setup Social Link Shortcode handler
 */
function oa_social_login_link_shortcode_handler ($args)
{
	if (is_user_logged_in ())
	{
		$user = wp_get_current_user ();
		if (!empty ($user->data->ID))
		{
			return oa_social_login_render_link_form ('shortcode', $user);
		}
	}
	return '';
}
add_shortcode ('oa_social_link', 'oa_social_login_link_shortcode_handler');


/**
 * Setup Social Link Action handler
 */
function oa_social_login_link_action_handler ()
{
	//Social Link works only with logged in users
	if (is_user_logged_in ())
	{
		$user = wp_get_current_user ();
		if (is_object ($user) AND !empty ($user->data->ID))
		{
			echo oa_social_login_render_link_form ('custom', $user);
		}
	}
}
add_action ('oa_social_link', 'oa_social_login_link_action_handler');


/**
 * **************************************************************************************************************
 * ************************************************ SOCIAL LOGIN ************************************************
 * **************************************************************************************************************
 */


/**
 * Setup Social Login Shortcode handler
 */
function oa_social_login_shortcode_handler ($args)
{
	if (!is_user_logged_in ())
	{
		return oa_social_login_render_login_form ('shortcode');
	}
	return '';
}
add_shortcode ('oa_social_login', 'oa_social_login_shortcode_handler');


/**
 * Social Login Shortcode Tests
 *
 * [oa_social_login_test is_social_login_user="true"]
 *		This content is displayed for Social Login users
 * [oa_social_login_test]
 *
 * [oa_social_login_test is_social_login_user="false"]
 *		This content is displayed for users that aren't Social Login users
 * [oa_social_login_test]
 *
 */
function oa_social_login_shortcode_test ($args, $content = null)
{
	if (is_array ($args))
	{
		$user = wp_get_current_user ();
		$is_social_login_user = false;

		if (!empty ($user->data->ID))
		{
			$identity_providers = trim (strval (get_user_meta ($user->data->ID, 'oa_social_login_identity_provider', true)));
			$is_social_login_user = (strlen ($identity_providers) > 0);
		}

		if (!empty ($args ['is_social_login_user']))
		{
			if ($args ['is_social_login_user'] == 'true')
			{
				if ($is_social_login_user)
				{
					return do_shortcode ($content);
				}
			}
			elseif ($attr ['is_social_login_user'] == 'false')
			{
				if (!$is_social_login_user)
				{
					return do_shortcode ($content);
				}
			}
		}
	}
}
add_shortcode ('oa_social_login_test', 'oa_social_login_shortcode_test');


/**
 * Hook to display custom avatars (Buddypress specific)
 */
function oa_social_login_bp_custom_fetch_avatar ($text, $args)
{
	//The social login settings
	static $oa_social_login_avatars = null;
	if (is_null ($oa_social_login_avatars))
	{
		$oa_social_login_settings = get_option ('oa_social_login_settings');
		$oa_social_login_avatars = (isset ($oa_social_login_settings ['plugin_show_avatars_in_comments']) ? $oa_social_login_settings ['plugin_show_avatars_in_comments'] : 0);
	}

	//Check if avatars are enabled
	if (!empty ($oa_social_login_avatars))
	{
		//Check arguments
		if (is_array ($args))
		{
			//User Object
			if (!empty ($args ['object']) AND strtolower ($args ['object']) == 'user')
			{
				//User Identifier
				if (!empty ($args ['item_id']) AND is_numeric ($args ['item_id']))
				{
					//Retrieve user
					if (($user_data = get_userdata ($args ['item_id'])) !== false)
					{
						//Fetch the BuddyPress avatar for this user
						$bp_user_avatar = strtolower (trim (strval (bp_core_fetch_avatar (array ('item_id' => $args ['item_id'], 'no_grav' => true, 'html' => false)))));

						//Fetch the default BuddyPress avatar
						$bp_default_avatar = strtolower (trim (strval (bp_core_avatar_default ('local'))));

						//Only replace if the user has the default avatar (this will keep uploaded avatars)
						if (empty ($bp_user_avatar) OR empty ($bp_default_avatar) OR ($bp_user_avatar == $bp_default_avatar))
						{
							//Read the avatar
							$user_meta_thumbnail = get_user_meta ($args ['item_id'], 'oa_social_login_user_thumbnail', true);
							$user_meta_picture = get_user_meta ($args ['item_id'], 'oa_social_login_user_picture', true);

							//Use the picture if possible
							if ($oa_social_login_avatars == 2)
							{
								$user_picture = (!empty ($user_meta_picture) ? $user_meta_picture : $user_meta_thumbnail);
							}
							//Use the thumbnail if possible
							else
							{
								$user_picture = (!empty ($user_meta_thumbnail) ? $user_meta_thumbnail : $user_meta_picture);
							}

							//Avatar found?
							if ($user_picture !== false AND strlen (trim ($user_picture)) > 0)
							{
								//Build Image tags
								$img_alt = (!empty ($args ['alt']) ? 'alt="' . oa_social_login_esc_attr ($args ['alt']) . '" ' : '');
								$img_alt = sprintf ($img_alt, htmlspecialchars ($user_data->user_login));
								$img_class = ('class="' . (!empty ($args ['class']) ? ($args ['class'] . ' ') : '') . 'avatar-social-login" ');
								$img_width = (!empty ($args ['width']) ? 'width="' . $args ['width'] . '" ' : '');
								$img_height = (!empty ($args ['height']) ? 'height="' . $args ['height'] . '" ' : '');

								//Replace
								$text = preg_replace ('#<img[^>]+>#i', '<img data-social-login="bp-d1" src="' . $user_picture . '" ' . $img_alt . $img_class . $img_height . $img_width . '/>', $text);
							}
						}
					}
				}
			}
		}
	}
	return $text;
}
add_filter ('bp_core_fetch_avatar', 'oa_social_login_bp_custom_fetch_avatar', 10, 2);


/**
 * Hook to display custom avatars
 */
function oa_social_login_custom_avatar ($avatar, $mixed, $size, $default, $alt = '')
{
	//The social login settings
	static $oa_social_login_avatars = null;
	if (is_null ($oa_social_login_avatars))
	{
		$oa_social_login_settings = get_option ('oa_social_login_settings');
		$oa_social_login_avatars = (isset ($oa_social_login_settings ['plugin_show_avatars_in_comments']) ? $oa_social_login_settings ['plugin_show_avatars_in_comments'] : 0);
	}

	//Check if social avatars are enabled
	if (!empty ($oa_social_login_avatars))
	{
		//Check if we have an user identifier
		if (is_numeric ($mixed) AND $mixed > 0)
		{
			$user_id = $mixed;
		}
		//Check if we have an user email
		elseif (is_string ($mixed) AND ($user = get_user_by ('email', $mixed)))
		{
			$user_id = $user->ID;
		}
		//Check if we have an user object
		elseif (is_object ($mixed) AND property_exists ($mixed, 'user_id') AND is_numeric ($mixed->user_id))
		{
			$user_id = $mixed->user_id;
		}
		//None found
		else
		{
			$user_id = null;
		}

		//User found?
		if (!empty ($user_id))
		{
			//Override current avatar ?
			$override_avatar = true;

			//BuddyPress (Thumbnails in the default WordPress toolbar)
			if (function_exists ('bp_core_fetch_avatar') AND function_exists ('bp_core_avatar_default'))
			{
				//Fetch the BuddyPress user avatar
				$bp_user_avatar = bp_core_fetch_avatar (array ('item_id' => $user_id, 'no_grav' => true, 'html' => false));

				//Do not override if it's not the default avatar
				if (!empty ($bp_user_avatar) AND $bp_user_avatar <> bp_core_avatar_default ())
				{
					//User has probably upladed an avatar
					$override_avatar = false;
				}
			}

			//Show avatar?
			if ($override_avatar)
			{
				//Read the avatar
				$user_meta_thumbnail = get_user_meta ($user_id, 'oa_social_login_user_thumbnail', true);
				$user_meta_picture = get_user_meta ($user_id, 'oa_social_login_user_picture', true);

				//Use the picture if possible
				if ($oa_social_login_avatars == 2)
				{
					$user_picture = (!empty ($user_meta_picture) ? $user_meta_picture : $user_meta_thumbnail);
				}
				//Use the thumbnail if possible
				else
				{
					$user_picture = (!empty ($user_meta_thumbnail) ? $user_meta_thumbnail : $user_meta_picture);
				}

				//Avatar found?
				if ($user_picture !== false AND strlen (trim ($user_picture)) > 0)
				{
					return '<img alt="' . oa_social_login_esc_attr ($alt) . '" src="' . $user_picture . '" class="avatar avatar-social-login avatar-' . $size . ' photo" height="' . $size . '" width="' . $size . '" />';
				}
			}
		}
	}

	//Default
	return $avatar;
}
add_filter ('get_avatar', 'oa_social_login_custom_avatar', 10, 5);


/**
 * Show Social Login below "you must be logged in ..."
 */
function oa_social_login_filter_comment_form_defaults ($default_fields)
{
	//No need to go further if comments disabled or user loggedin
	if (is_array ($default_fields) AND comments_open () AND !is_user_logged_in ())
	{
		//Read settings
		$settings = get_option ('oa_social_login_settings');

		//Display buttons if option not set or disabled
		if (!empty ($settings ['plugin_comment_show_if_members_only']))
		{
			if (!isset ($default_fields ['must_log_in']))
			{
				$default_fields ['must_log_in'] = '';
			}
			$default_fields ['must_log_in'] .= oa_social_login_render_login_form ('comments');
		}
	}
	return $default_fields;
}
add_filter ('comment_form_defaults', 'oa_social_login_filter_comment_form_defaults');


/**
 * Display the provider grid for comments
 */
function oa_social_login_render_login_form_comments ()
{
	//Comments are open and the user is not logged in
	if (comments_open () && !is_user_logged_in ())
	{
		//Read settings
		$settings = get_option ('oa_social_login_settings');

		//Display buttons if option not set or not disabled
		if (!isset ($settings ['plugin_comment_show']) OR !empty ($settings ['plugin_comment_show']))
		{
			echo oa_social_login_render_login_form ('comments');
		}
	}
}
//WordPress Comments
add_action ('comment_form_top', 'oa_social_login_render_login_form_comments');

//Appthemes Thesis Theme Comments
add_action ('thesis_hook_comment_form_top', 'oa_social_login_render_login_form_comments');


/**
 * Display the provider grid for registration
 */
function oa_social_login_render_login_form_registration ()
{
	//Users may register
	if (get_option ('users_can_register') === '1')
	{
		//Read settings
		$settings = get_option ('oa_social_login_settings');

		//Display buttons if option not set or enabled
		if (!isset ($settings ['plugin_display_in_registration_form']) OR !empty ($settings ['plugin_display_in_registration_form']))
		{
			echo oa_social_login_render_login_form ('registration');
		}
	}
}

//WordPress Signup Form
add_action ('after_signup_form', 'oa_social_login_render_login_form_registration');

//BuddyPress Registration
add_action ('bp_before_account_details_fields', 'oa_social_login_render_login_form_registration');


/**
 * Display the provider grid for login
 */
function oa_social_login_render_login_form_login ()
{
	//Read settings
	$settings = get_option ('oa_social_login_settings');

	//Display buttons only if option not set or enabled
	if (!isset ($settings ['plugin_display_in_login_form']) OR $settings ['plugin_display_in_login_form'] == '1')
	{
		echo oa_social_login_render_login_form ('login');
	}
}

//WordPress Profile Builder
add_action ('wppb_before_login', 'oa_social_login_render_login_form_login');

//BuddyPress Sidebar
add_action ('bp_before_sidebar_login_form', 'oa_social_login_render_login_form_login');

//Appthemes Vantage Theme
add_action ('va_after_admin_bar_login_form', 'oa_social_login_render_login_form_login');

//Sidebar Login
add_action ('sidebar_login_widget_logged_out_content_end', 'oa_social_login_render_login_form_login');


/**
 * Display the provider grid for login - with a specific callback_uri
 */
function oa_social_login_render_login_form_wp_login ()
{
	//Read settings
	$settings = get_option ('oa_social_login_settings');

	//Display buttons only if option not set or enabled
	if (!isset ($settings ['plugin_display_in_login_form']) OR !empty ($settings ['plugin_display_in_login_form']))
	{
		//Additional arguments for the login icons
		$args = array ();

		//Only on the login page
		if (strpos (oa_social_login_get_current_url (), 'wp-login.php') !== false)
		{
			//This is the default WordPress login url
			$args ['callback_uri'] = site_url ('wp-login.php', 'login_post');

			//Add our query argument
			$args ['callback_uri'] = add_query_arg (array ('oa_social_login_source' => 'login'), $args ['callback_uri']);

			//Allow others to customize the callback uri
			$args ['callback_uri'] = apply_filters ('oa_social_login_filter_wp_login_callback_uri', $args ['callback_uri']);
		}

		//Include it as parameter
		echo oa_social_login_render_login_form ('login', $args);
	}
}
//WordPress Login Form
add_action ('login_form', 'oa_social_login_render_login_form_wp_login');


/**
 * Display the provider grid for registration - with a specific callback_uri
 */
function oa_social_login_render_login_form_wp_registration ()
{
	//Users may register
	if (get_option ('users_can_register') === '1')
	{
		//Read settings
		$settings = get_option ('oa_social_login_settings');

		//Display buttons if option not set or enabled
		if (!isset ($settings ['plugin_display_in_registration_form']) OR !empty ($settings ['plugin_display_in_registration_form']))
		{
			//Additional arguments for the icon builder
			$args = array ();

			//Only on the registration page
			if (strpos (oa_social_login_get_current_url (), 'wp-login.php') !== false)
			{
				//This is the default WordPress registration url
				$args ['callback_uri'] = site_url ('wp-login.php?action=register', 'login_post');

				//Add our query argument
				$args ['callback_uri'] = add_query_arg (array ('oa_social_login_source' => 'registration'), $args ['callback_uri']);

				//Others may use this hook
				$args ['callback_uri'] = apply_filters ('oa_social_login_filter_wp_registration_callback_uri', $args ['callback_uri']);
			}

			echo oa_social_login_render_login_form ('registration', $args);
		}
	}
}
//WordPress Registration Form
add_action ('register_form', 'oa_social_login_render_login_form_wp_registration');


/**
 * Display a custom grid for login
 */
function oa_social_login_render_custom_form_login ()
{
	if (!is_user_logged_in ())
	{
		echo oa_social_login_render_login_form ('custom');
	}
}
add_action ('oa_social_login', 'oa_social_login_render_custom_form_login');


/**
 * Alternative for custom forms, where the output is not necessarily required at the place of calling
 * $oa_social_login_form = apply_filters('oa_social_login_custom', '');
 */
function oa_social_login_filter_login_form_custom ($value = 'custom')
{
	return (is_user_logged_in () ? '' : oa_social_login_render_login_form ($value));
}
add_filter ('oa_social_login_custom', 'oa_social_login_filter_login_form_custom');


/**
 * Display the provider grid
 */
function oa_social_login_render_login_form ($source, $args = array ())
{
	//Import providers
	GLOBAL $oa_social_login_providers;

	//Parse args
	$args = (is_array ($args) ? $args : array ());

	//Container for returned value
	$output = '';

	//Read settings
	$settings = get_option ('oa_social_login_settings');

	//API Subdomain
	$api_subdomain = (!empty ($settings ['api_subdomain']) ? $settings ['api_subdomain'] : '');

	//API Subdomain Required
	if (!empty ($api_subdomain))
	{
		//Build providers
		$providers = array ();
		if (is_array ($settings ['providers']))
		{
			foreach ($settings ['providers'] AS $settings_provider_key => $settings_provider_name)
			{
				if (isset ($oa_social_login_providers [$settings_provider_key]))
				{
					$providers [] = $settings_provider_key;
				}
			}
		}

		//Themes are served from the CDN
		$theme_uri_prefix = (oa_social_login_https_on () ? 'https://secure.oneallcdn.com' : 'http://public.oneallcdn.com');

		//Themes
		$css_theme_uri_small = $theme_uri_prefix . '/css/api/socialize/themes/wordpress/small.css';
		$css_theme_uri_default = $theme_uri_prefix . '/css/api/socialize/themes/wordpress/default.css';

		//Widget
		if ($source == 'widget')
		{
			//Read widget settings
			$widget_settings = (is_array ($args) ? $args : array ());

			//Don't show the title - this is handled insided the widget
			$plugin_caption = '';

			//Buttons size
			$css_theme_uri = ((array_key_exists ('widget_use_small_buttons', $widget_settings) AND !empty ($widget_settings ['widget_use_small_buttons'])) ? $css_theme_uri_small : $css_theme_uri_default);

			//Custom CSS
			$css_theme_uri = apply_filters ('oa_social_login_widget_css', $css_theme_uri);
		}
		//Other places
		else
		{
			//Show title if set
			$plugin_caption = (!empty ($settings ['plugin_caption']) ? $settings ['plugin_caption'] : '');

			//Buttons size
			$css_theme_uri = (!empty ($settings ['plugin_use_small_buttons']) ? $css_theme_uri_small : $css_theme_uri_default);

			//Custom CSS
			$css_theme_uri = apply_filters ('oa_social_login_default_css', $css_theme_uri);
		}

		//Build Callback URI
		if (array_key_exists ('callback_uri', $args) AND !empty ($args ['callback_uri']))
		{
			$callback_uri = "'" . $args ['callback_uri'] . "'";
		}
		else
		{
			$callback_uri = "(window.location.href + ((window.location.href.split('?')[1] ? '&amp;': '?') + \"oa_social_login_source=" . $source . "\"))";
		}

		//No providers selected
		if (count ($providers) == 0)
		{
			$output = '<div style="color:white;background-color:red;">[Social Login] ' . __ ('Please enable at least one social network!', 'oa_social_login') . '</div>';
		}
		//Providers selected
		else
		{

			//Setup output
			$output = array ();
			$output [] = " <!-- OneAll.com / Social Login for WordPress / v" . constant ('OA_SOCIAL_LOGIN_VERSION') . " -->";
			$output [] = '<div class="oneall_social_login">';

			//Add the caption?
			if (!empty ($plugin_caption))
			{
				$output [] = ' <div class="oneall_social_login_label" style="margin-bottom: 3px;"><label>' . __ ($plugin_caption) . '</label></div>';
			}

			//Add the Plugin
			$containerid = 'oneall_social_login_providers_' . mt_rand (99999, 9999999);
			$output [] = ' <div class="oneall_social_login_providers" id="' . $containerid . '"></div>';
			$output [] = ' <script type="text/javascript">';

			//Synchronous JavaScript: This is the default to stay compatible with existing installations.
			if (empty ($settings ['asynchronous_javascript']))
			{
				$output [] = "  oneall.api.plugins.social_login.build('" . $containerid . "', {";
				$output [] = "   'providers': ['" . implode ("','", $providers) . "'], ";
				$output [] = "   'callback_uri': " . $callback_uri . ", ";
				$output [] = "   'css_theme_uri': '" . $css_theme_uri . "' ";
				$output [] = "  });";
			}
			//Asynchronous JavaScript.
			else
			{
				//JavaScript Method Reference: http://docs.oneall.com/api/javascript/library/methods/
				$output [] = "  var _oneall = _oneall || [];";
				$output [] = "  _oneall.push(['social_login', 'set_providers', ['" . implode ("','", $providers) . "']]);";
				$output [] = "  _oneall.push(['social_login', 'set_callback_uri', " . $callback_uri . "]);";
				$output [] = "  _oneall.push(['social_login', 'set_custom_css_uri', '" . $css_theme_uri . "']);";
				$output [] = "  _oneall.push(['social_login', 'do_render_ui', '" . $containerid . "']);";
			}

			$output [] = " </script>";
			$output [] = '</div>';

			//Done
			$output = implode ("\n", $output);
		}

		//Return a string and let the calling function do the actual outputting
		return $output;
	}
}


/**
 * Request email from user
 */
function oa_social_login_request_email ()
{
	//Get the current user
	$current_user = wp_get_current_user ();

	//Check if logged in
	if (!empty ($current_user->ID) AND is_numeric ($current_user->ID))
	{
		//Current user
		$user_id = $current_user->ID;

		//Check if email has to be requested
		$oa_social_login_request_email = get_user_meta ($user_id, 'oa_social_login_request_email', true);
		if (!empty ($oa_social_login_request_email))
		{
			//Display modal dialog?
			$display_modal = true;

			//Messaging
			$message = '';

			//Read settings
			$settings = get_option ('oa_social_login_settings');

			//Make sure that the email is still required
			if (empty ($settings ['plugin_require_email']))
			{
				//Do not display the modal dialog
				$display_modal = false;

				//Stop asking for the email
				delete_user_meta ($user_id, 'oa_social_login_request_email');
			}

			//Form submitted
			if (isset ($_POST) AND !empty ($_POST ['oa_social_login_action']))
			{
				if ($_POST ['oa_social_login_action'] == 'confirm_email')
				{
					$user_email = (empty ($_POST ['oa_social_login_email']) ? '' : trim ($_POST ['oa_social_login_email']));
					$user_email = apply_filters ('oa_social_login_filter_user_request_email', $user_email, $user_id);

					if (empty ($user_email))
					{
						$message = __ ('Please enter your email address', 'oa_social_login');
					}
					else
					{
						if (!is_email ($user_email))
						{
							$message = __ ('This email is not valid', 'oa_social_login');
						}
						elseif (email_exists ($user_email))
						{

							$message = __ ('This email is already used by another account', 'oa_social_login');
						}
						else
						{
							//Read user
							$user_data = get_userdata ($user_id);
							if ($user_data !== false)
							{
								//Store old email
								$old_user_email = $user_data->user_email;

								//Update user
								wp_update_user (array ('ID' => $user_data->ID, 'user_email' => $user_email));
								delete_user_meta ($user_data->ID, 'oa_social_login_request_email');

								//Set new email for hook
								$user_data->user_email = $user_email;

								//Hook after having updated the email
								do_action ('oa_social_login_action_on_user_enter_email', $user_id, $user_data, $old_user_email);

								//No longer needed
								$display_modal = false;
							}
						}
					}
				}
			}

			//Display modal dialog?
			if ($display_modal === true)
			{
				//Read Settings
				$oa_social_login_settings = get_option ('oa_social_login_settings');

				//Read the social network
				$oa_social_login_identity_provider = get_user_meta ($user_id, 'oa_social_login_identity_provider', true);

				//Caption
				$caption = (isset ($oa_social_login_settings ['plugin_require_email_text']) ? $oa_social_login_settings ['plugin_require_email_text'] : __ ('<strong>We unfortunately could not retrieve your email address from %s.</strong> Please enter your email address in the form below in order to continue.', 'oa_social_login'));

				//Add CSS
				oa_social_login_add_site_css ();

				//Show email request form
?>
					<div id="oa_social_login_overlay"></div>
					<div id="oa_social_login_modal">
						<div class="oa_social_login_modal_outer">
							<div class="oa_social_login_modal_inner">
			 					<div class="oa_social_login_modal_title">
			 						<?php
													 printf (__ ('You have successfully connected with %s!', 'oa_social_login'), '<strong>' . $oa_social_login_identity_provider . '</strong>');
									 ?>
			 					</div>
			 					<?php
												 if (strlen (trim ($caption)) > 0)
												 {
								 ?>
			 									<div class="oa_social_login_modal_notice"><?php echo str_replace ('%s', $oa_social_login_identity_provider, $caption); ?></div>
			 								<?php
															 }
											 ?>
			 					<div class="oa_social_login_modal_body">
				 					<div class="oa_social_login_modal_subtitle">
				 						<?php _e ('Please enter your email address', 'oa_social_login'); ?>:
				 					</div>
									<form method="post" action="">
										<fieldset>
											<div>
												<input type="text" name="oa_social_login_email" class="oa_social_login_confirm_text" value="<?php echo (!empty ($_POST ['oa_social_login_email']) ? oa_social_login_esc_attr ($_POST ['oa_social_login_email']) : ''); ?>" />
												<input type="hidden" name="oa_social_login_action" value="confirm_email" size="30" />
											</div>
											<div class="oa_social_login_modal_error">
												<?php echo $message; ?>
											</div>
											<div class="oa_social_login_buttons">
												<input class="oa_social_login_button_confirm" type="submit" value="<?php _e ('Confirm', 'oa_social_login'); ?>" />
												<input class="oa_social_login_button_cancel" type="button" value="<?php _e ('Cancel', 'oa_social_login'); ?>" onclick="window.location.href='<?php echo esc_url (wp_logout_url (oa_social_login_get_current_url ())); ?>'" />
											</div>
										</fieldset>
									</form>
								</div>
							</div>
						</div>
					</div>
				<?php
			}
		}
	}
}
add_action ('wp_footer', 'oa_social_login_request_email');
add_action ('admin_footer', 'oa_social_login_request_email');