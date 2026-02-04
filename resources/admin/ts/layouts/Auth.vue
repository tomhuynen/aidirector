<template>
  <AuthLayout :title="$t('Log in to your account')" :description="$t('Enter your email and password below to log in')">
    <slot />
  </AuthLayout>
  <Toaster />
</template>
<script setup lang="ts">
import AuthLayout from '@admin:layouts/auth/Simple.vue'
import { $t } from '@admin:shared/i18n'
import { Toaster } from 'vue-sonner'
</script>
