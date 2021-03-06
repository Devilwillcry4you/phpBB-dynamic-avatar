<?php
/**
*
* @package Dynamo (Dynamic Avatar MOD for phpBB3)
* @version $Id: acp_dynamo.php ilostwaldo@gmail.com$
* @copyright (c) 2011 dellsystem (www.dellsystem.me)
* @license http://opensource.org/licenses/gpl-license.php GNU Public License 
*
*/

/**
* @ignore
*/
if (!defined('IN_PHPBB'))
{
	exit;
}

/**
* ucp_adynamo
* Dynamic Avatar
* @package ucp
*/
class ucp_dynamo
{
	var $u_action;

	function main($id, $mode)
	{
		global $template, $user, $db, $config, $phpEx, $phpbb_root_path;

		include($phpbb_root_path . 'includes/functions_dynamo.' . $phpEx);
		$submit = (isset($_POST['submit'])) ? true : false;
		$user_id = $user->data['user_id'];

		switch ($mode)
		{
			case 'edit':
				$user->add_lang('mods/dynamo/ucp');

				// Get all the info when submitting or when just showing for convenience
				// First, get the list of possible layers
				$layers = array();
				$sql = "SELECT *
						FROM " . DYNAMO_LAYERS_TABLE;
				$result = $db->sql_query($sql);

				while ($row = $db->sql_fetchrow($result))
				{
					$layer_id = $row['dynamo_layer_id'];
					$mandatory = $row['dynamo_layer_mandatory'];
					$default = $row['dynamo_layer_default'];
					$name = $row['dynamo_layer_name'];
					$desc = $row['dynamo_layer_desc'];
					$position = $row['dynamo_layer_position'];

					$layers[$layer_id] = array(
						'items' 	=> array(), // To be filled in later (assoc)
						'mandatory'	=> $mandatory,
						'default'	=> $default,
						'name'		=> $name,
						'desc'		=> $desc,
						'position'	=> $position,
						'desired'	=> request_var('layer-' . $layer_id, 0),
						'current'	=> 0, // Only used for showing, not submitting
					);
					
					// If the layer is not mandatory, add 0 to the items list
					if (!$mandatory)
					{
						$layers[$layer_id]['items'][0] = array(
							'name'	=> $user->lang['NO_ITEM'],
							'desc'	=> $user->lang['NO_ITEM'],
							'url'	=> 'images/spacer.gif',
						);
					}
				}

				// Then get the list of possible items
				$sql = "SELECT *
						FROM " . DYNAMO_ITEMS_TABLE;
				$result = $db->sql_query($sql);

				while ($row = $db->sql_fetchrow($result))
				{
					$layer_id = $row['dynamo_item_layer'];
					$name = $row['dynamo_item_name'];
					$desc = $row['dynamo_item_desc'];
					$item_id = $row['dynamo_item_id'];

					$layers[$layer_id]['items'][$item_id] = array(
						'name'		=> $name,
						'desc'		=> $desc,
						'url'		=> get_item_image_path('entire', $layer_id, $item_id),
					);
				}

				if ($submit)
				{
					// Save a list of URLs to the desired item images
					$images = array();

					// Go through all the items, make sure they pass
					// Also fill in the update array
					$insert_array = array();

					foreach ($layers as $layer_id => $layer_data)
					{
						$desired_item = $layer_data['desired'];

						// Check if the desired item is in the list of possible items (maybe incl 0)
						// If not, check if it's 0 and the layer is not mandatory
						$item_is_valid = isset($layer_data['items'][$desired_item]);
						$no_item_selected = ($desired_item == 0) ? 1 : 0;

						if ($item_is_valid)
						{
							// Only add to the images array if the item is valid (not 0)
							if ($desired_item > 0)
							{
								$images[] = $layers[$layer_id]['items'][$desired_item]['url'];
							}

							// Even if it's not valid, we add it to the SQL insert array
							$insert_array[] = array(
								'dynamo_user_layer' 	=> $layer_id,
								'dynamo_user_item'		=> $desired_item,
								'dynamo_user_id'		=> $user_id,
							);
						}
						else
						{
							// Trigger a custom error message depending on the situation
							$message = ($no_item_selected) ? $user->lang['MANDATORY_LAYER'] : $user->lang['INVALID_ITEM'];
							trigger_error(sprintf($message, $layer_data['name']));
						}
					}

					// We've made it this far - now we can go ahead and delete the dynamo users table stuff
					$sql = "DELETE FROM " . DYNAMO_USERS_TABLE . "
							WHERE dynamo_user_id = $user_id";
					$db->sql_query($sql);

					// Now insert into the table
					$db->sql_multi_insert(DYNAMO_USERS_TABLE, $insert_array);

					// Create the image from the specified layers, save it to disk
					// Act on the first image, then move up
					$first_image = imagecreatefrompng($images[0]);
					for ($i = 1, $length = count($images); $i < $length; $i++)
					{
						$this_image = imagecreatefrompng($images[$i]);
						// If an image is larger than the default then it will look weird
						// Should find the largest width/height, and use that below
						// Then resize it etc (so no cropping, preferrably)
						merge_images($first_image, $this_image, 0, 0, 0, 0, $config['dynamo_width'], $config['dynamo_height'], 100);
					}

					imagesavealpha($first_image, true);
					$avatar_path = get_avatar_image_path($user_id);
					imagepng($first_image, $avatar_path);

					// For now, pretend it's a remote avatar and modify the user's avatar-related fields accordingly
					$update_array = array(
						'user_avatar' 			=> generate_board_url() . '/' . $avatar_path,
						'user_avatar_type'		=> 2, // means remote
						'user_avatar_width'		=> $config['dynamo_width'],
						'user_avatar_height'	=> $config['dynamo_height'],
					);

					$sql = "UPDATE " . USERS_TABLE . "
							SET " . $db->sql_build_array('UPDATE', $update_array) . "
							WHERE user_id = " . (int) $user_id;
					$db->sql_query($sql);

					$message = $user->lang['UCP_DYNAMO_UPDATED'] . '<br /><br />' . sprintf($user->lang['RETURN_UCP'], '<a href="' . $this->u_action . '">', '</a>');
					trigger_error($message);
				}

				$this->tpl_name = 'ucp_dynamo_edit';
				$this->page_title = 'Edit avatar';
				
				// Get the user's items
				$sql = "SELECT *
						FROM " . DYNAMO_USERS_TABLE . "
						WHERE dynamo_user_id = $user_id";
				$result = $db->sql_query($sql);
				
				while ($row = $db->sql_fetchrow($result))
				{
					// Update the $layers array - current item
					// If there is no data in the dynamo users table, set to 0
					$layer_id = $row['dynamo_user_layer'];
					$item_id = $row['dynamo_user_item'];
					$layers[$layer_id]['current'] = $item_id;
				}

				// Create the template loops and stuff by looping through $layers
				foreach ($layers as $layer_id => $layer_data)
				{
					// If the item exists in the layer, use that
					// Otherwise, choose the default item
					$current_item = $layer_data['current'];
					$item_exists = isset($layer_data['items'][$current_item]) && $current_item > 0;
					$default = $layer_data['default'];
					$true_item = ($item_exists) ? $current_item : $default;

					// For making it sort of work even without js
					$template->assign_block_vars('image', array(
						'LAYER_ID'		=> $layer_id,
						'POSITION'		=> $layer_data['position'],
						'ITEM_EXISTS'	=> $item_exists,
						'URL'			=> $layer_data['items'][$true_item]['url'],
						'ORIGINAL'		=> $true_item,
						'DEFAULT'		=> $default,
					));

					$template->assign_block_vars('layer', array(
						'ID'			=> $layer_id,
						'NAME'			=> $layer_data['name'],
						'DESC'			=> $layer_data['desc'],
						'TRUE_ITEM'		=> $true_item,
						'MANDATORY'		=> $layer_data['mandatory'],
					));

					// Now loop through the items in this layer
					foreach ($layer_data['items'] as $item_id => $item_data)
					{
						$template->assign_block_vars('layer.item', array(
							'URL'		=> $item_data['url'],
							'NAME'		=> $item_data['name'],
							'DESC'		=> $item_data['desc'],
							'ID'		=> $item_id,
						));
					}
				}
			break;
		}

		$template->assign_vars(array(
			'IMAGE_HEIGHT'	=> $config['dynamo_height'],
			'IMAGE_WIDTH'	=> $config['dynamo_width'],
			'U_ACTION'	=> $this->u_action,
			'L_TITLE' 	=> $this->page_title)
		);

	}
}

?>
