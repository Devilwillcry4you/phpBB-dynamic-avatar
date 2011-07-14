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
		
		$submit = (isset($_POST['submit'])) ? true : false;
		$user_id = $user->data['user_id'];

		switch ($mode)
		{
			case 'edit':
				if ($submit)
				{
					// Get all the values we need, using the layers as stored in the db for security-ish
					
					// First get all the rows for this user from the dynamo users table
					$sql = "SELECT dynamo_user_layer
							FROM " . DYNAMO_USERS_TABLE . "
							WHERE dynamo_user_id = $user_id";
					$result = $db->sql_query($sql);
					
					$layers_to_update = array();
					
					// Push them to an array so we can get them later
					while ($row = $db->sql_fetchrow($result))
					{
						$layers_to_update[$row['dynamo_user_layer']] = 1; // meh
					}
					
					// Get info for each layer
					$sql = "SELECT dynamo_layer_id, dynamo_layer_default, dynamo_layer_mandatory
							FROM " . DYNAMO_LAYERS_TABLE;
					$result = $db->sql_query($sql);
					
					$insert_query = '';
					$update_query = '';
					while ($row = $db->sql_fetchrow($result))
					{
						$layer_id = $row['dynamo_layer_id'];
						$layer_default = $row['dynamo_layer_default'];
						$layer_mandatory = $row['dynamo_layer_mandatory'];
						
						// First get the POST value for this layer (from the form)
						$this_item = request_var('layer-' . $layer_id, 0);
						
						// If it's set to 0, and the layer is mandatory, use the default item
						if ($this_item == 0 && $layer_mandatory)
						{
							$this_item = $layer_default;
						}
						
						// Check if this layer should be inserted or updated
						// Does it exist in the layers_to_update array?
						if ($layers_to_update[$layer_id] == 1)
						{
							// Have to do like a thousand update queries
							$sql = "UPDATE " . DYNAMO_USERS_TABLE . "
									SET dynamo_user_item = $this_item
									WHERE dynamo_user_id = $user_id
										AND dynamo_user_layer = $layer_id";
							$db->sql_query($sql);
						}
						else
						{
							// Otherwise, add to the insert query
							$insert_query .= "($user_id, $layer_id, $this_item), ";
						}
					}
					
					// Now insert everything new into the dynamo users table if there is anything new
					if ($insert_query != '')
					{
						// Get rid of the last comma first or things break. Really? Really.
						$insert_query = substr($insert_query, 0, -2);
						$sql = "INSERT INTO " . DYNAMO_USERS_TABLE . " (dynamo_user_id, dynamo_user_layer, dynamo_user_item) VALUES $insert_query";
						$db->sql_query($sql);
					}
				
					// Do validation stuff another time
					
					// Now create the actual image, make it the user's avatar (deletes the old one I guess)
					// Later - for now, just save an avatar image
				
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
				
				$user_items = array();
				
				while ($row = $db->sql_fetchrow($result))
				{
					// Store the items in an associative array
					// Key: layer ID, value: item ID
					$this_layer_id = $row['dynamo_user_layer'];
					$this_item_id = $row['dynamo_user_item'];
					$user_items[$this_layer_id] = $this_item_id;
				}
				
				// Get the possible items
				$sql = "SELECT i.dynamo_item_id, i.dynamo_item_name, i.dynamo_item_layer, i.dynamo_item_desc, l.dynamo_layer_name, l.dynamo_layer_position, l.dynamo_layer_mandatory, l.dynamo_layer_default
						FROM " . DYNAMO_ITEMS_TABLE . " i
						LEFT JOIN " . DYNAMO_LAYERS_TABLE . " l
						ON i.dynamo_item_layer = l.dynamo_layer_id
						ORDER BY l.dynamo_layer_position DESC";
				$result = $db->sql_query($sql);
				
				// Make an array holding all the layers so we can loop through it later without doing another query
				$layers_array = array();
				// And a different array holding the default items ... same indexing scheme as above
				$defaults_array = array();
				// And an array for the positions. There should be a shortcut for this since it's ordered by position ...
				$positions_array = array();
				
				$previous_layer = '';
				$num_layers = 0;
				
				while ($row = $db->sql_fetchrow($result))
				{
					$item_layer = $row['dynamo_item_layer'];
					$new_layer = ($previous_layer != $item_layer);
					
					if ($new_layer)
					{
						// If it's a new layer, add it to the layers arrays
						array_push($layers_array, $item_layer);
						array_push($defaults_array, $row['dynamo_layer_default']);
						array_push($positions_array, $row['dynamo_layer_position']);
						$num_layers++; // for use in determining first-layerness and in the length of the array lol
					}
					
					// For determining if we need a new row or not
					$num_in_layer = ($new_layer) ? 1 : $num_in_layer + 1;
					
					$item_id = $row['dynamo_item_id'];
				
					// Figure out the item's image URL
					$item_image_url = 'images/dynamo/' . $item_layer . '-' . $item_id . '.png';
					
					// Now figure out if the user has this item already
					if (!$user_items[$item_layer] > 0)
					{
						// Check if this is the default item
						// Only enabled when the user does not have an item for this
						$selected = ($item_id == $row['dynamo_layer_default']) ? true : false;
					}
					else
					{
						// User does have an item for this layer, is this the one?
						$selected = ($user_items[$item_layer] === $item_id) ? true : false;
					}
				
					$template->assign_block_vars('item', array(
						'NEW_LAYER'		=> $new_layer,
						// None selected - if the user has no item for this layer, or the item_id is 0, and there is no default
						'NONE_SELECTED'	=> (!($user_items[$item_layer] > 0) && $row['dynamo_layer_default'] == 0) ? 'checked="checked"' : '',
						'SELECTED'		=> ($selected) ? 'checked="checked"' : '',
						'NUM_IN_LAYER'	=> $num_in_layer,
						'ITEM_NAME'		=> $row['dynamo_item_name'],
						'LAYER_ID'		=> $item_layer,
						'ITEM_ID'		=> $item_id,
						'ITEM_DESC'		=> $row['dynamo_item_desc'],
						'ITEM_IMAGE'	=> $item_image_url,
						'LAYER_POSITION'=> $row['dynamo_layer_position'],
						'IS_MANDATORY'	=> $row['dynamo_layer_mandatory'],
						'FIRST_LAYER'	=> ($num_layers == 1) ? true : false,
						'U_EDIT'		=> $this->u_action . '&amp;edit=' . $item_id,
						'U_DELETE'		=> $this->u_action . '&amp;delete=' . $item_id,
						'LAYER_NAME'	=> ($item_layer) ? $row['dynamo_layer_name'] : 'Uncategorised')
					);
					$previous_layer = $item_layer;
				}
				
				
				// Now initially create the user's avatar so that it works even without javascript
				for ($i = 0; $i < $num_layers; $i++)
				{
					$this_layer = $layers_array[$i];
					// Figure out the item that should be here
					$this_layer_item = $user_items[$this_layer];
					if ($this_layer_item > 0)
					{
						// Use this as the item
						$item_to_use = $this_layer_item;
					}
					else
					{
						// If the user does not have an item for this layer:
						// Check if there is a default item
						if ($defaults_array[$i] > 0) // we can use $i because it's in the same order as the other array etc
						{
							// If so, set that to be the item
							$item_to_use = $defaults_array[$i];
						}
						else
						{
							// No item ... pass
							$item_to_use = 0; // for the item_exists boolean below
						}
					}
					
					// Now actually add the item to the page
					if (isset($item_to_use))
					{
						// so bad
						$template->assign_block_vars('images', array(
							'ITEM_ID'			=> $item_to_use,
							'LAYER_ID'			=> $this_layer,
							'POSITION'			=> $positions_array[$i],
							'ITEM_EXISTS'		=> ($item_to_use > 0) ? true : false,
							'ITEM_IMAGE'		=> $phpbb_root_path . 'images/dynamo/' . $this_layer . '-' . $item_to_use . '.png',
						));
					}
				}
			break;
		}


		$template->assign_vars(array(
			'U_ACTION'	=> $this->u_action,
			'L_TITLE' 	=> $this->page_title)
		);

	}
}

?>