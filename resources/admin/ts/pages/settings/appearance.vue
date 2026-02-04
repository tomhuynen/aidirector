<template>
  <HeadingSmall :title="$t('Appearance settings')" :description="$t('Update your account\'s appearance settings')" />
  <AppearanceTabs />
</template>

<script setup lang="ts">
import HeadingSmall from '@admin:components/HeadingSmall.vue'
import AppearanceTabs from '@admin:components/Settings/AppearanceTabs.vue'
import SettingsLayout from '@admin:layouts/settings/Layout.vue'
import { $t } from '@admin:shared/i18n'

defineOptions({
  layout: [SettingsLayout],
})
</script>
