<template>
	<div class="welcome">
		<div id="emptycontent">
			<div id="emptycontent-icon" class="icon-talk" />
			<h2>{{ t('spreed', 'Join a conversation or start a new one') }}</h2>
			<p class="emptycontent-additional">
				{{ t('spreed','Say hi to your friends and colleagues!') }}
			</p>
			<div id="shareRoomContainer">
			<!--  <input id="shareRoomInput" class="share-room-input hidden" readonly="readonly" type="text"/>
			<div id="shareRoomClipboardButton" class="shareRoomClipboard icon-clippy hidden" data-clipboard-target="#shareRoomInput"></div> -->
			</div>
		</div>
		<NewGroupConversation
			:has-tooltip="false">
			<button class="primary">
				{{ t('spreed', 'Create a new group conversation') }}
			</button>
		</NewGroupConversation>
	</div>
</template>

<script>
import NewGroupConversation from '../components/LeftSidebar/NewGroupConversation/NewGroupConversation'

export default {
	name: 'WelcomeView',
	components: {
		NewGroupConversation,
	},
}
</script>

<style lang="scss" scoped>
.welcome {
	display: flex;
	flex-direction: column;
}
#emptycontent {
	margin-bottom: 12px;
}
</style>
