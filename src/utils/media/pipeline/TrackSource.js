/**
 *
 * @copyright Copyright (c) 2021, Daniel Calviño Sánchez (danxuliu@gmail.com)
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

import EmitterMixin from '../../EmitterMixin'
import TrackSourceMixin from './TrackSourceMixin'

/**
 * Base class for source nodes of tracks.
 *
 * See TrackSourceMixin documentation for details.
 *
 * EmitterMixin is already applied, so subclasses do not need to apply it.
 *
 *        -------------
 *       |             | --->
 *       | TrackSource | ...
 *       |             | --->
 *        -------------
 */
export default class TrackSource {

	constructor() {
		this._superEmitterMixin()
		this._superTrackSourceMixin()
	}

}

EmitterMixin.apply(TrackSource.prototype)
TrackSourceMixin.apply(TrackSource.prototype)
