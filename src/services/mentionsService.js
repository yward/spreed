/**
 *
 * @copyright Copyright (c) 2020, Daniel Calviño Sánchez <danxuliu@gmail.com>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

/**
 * Fetch possible mentions
 *
 * @param {string} token The token of the conversation.
 * @param {string} searchText The string that will be used in the search query.
 */
const searchPossibleMentions = async function(token, searchText) {
	try {
		const response = await axios.get(generateOcsUrl('apps/spreed/api/v1/chat/{token}/mentions', { token }), {
			params: {
				search: searchText,
				includeStatus: 1,
			},
		})
		return response
	} catch (error) {
		console.debug('Error while searching possible mentions: ', error)
	}
}

export {
	searchPossibleMentions,
}
