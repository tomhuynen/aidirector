<template>
  <Head :title="$t('Home')" />

  <div class="flex min-h-svh flex-col">
    <header class="mx-auto flex h-14 w-full max-w-6xl items-center justify-between px-6">
      <span class="font-display text-lg font-medium">{{ app.title }}</span>
      <nav class="flex items-center gap-3">
        <template v-if="account">
          <Button as-child size="sm">
            <Link :href="account.links.projects">{{ $t('Open projects') }}</Link>
          </Button>
        </template>
        <template v-else>
          <Button as-child variant="ghost" size="sm">
            <Link :href="login.url()">{{ $t('Log in') }}</Link>
          </Button>
          <Button as-child size="sm">
            <Link :href="register.url()">{{ $t('Create account') }}</Link>
          </Button>
        </template>
      </nav>
    </header>

    <main class="mx-auto flex w-full max-w-6xl flex-1 flex-col justify-center gap-8 px-6 py-24">
      <p class="text-xs font-medium tracking-[0.18em] text-muted-foreground uppercase">{{ $t('AI art director') }}</p>
      <h1 class="font-display max-w-3xl text-5xl leading-[1.02] font-medium text-balance md:text-7xl">
        {{ $t('Say what a shot should communicate. Get the camera, the keyframes and the clip.') }}
      </h1>
      <p class="max-w-xl text-lg leading-relaxed text-muted-foreground">
        {{
          $t(
            'Set up a project with a purpose and a style. Describe each shot in plain words. The director proposes how to film it, generates the keyframes and hands a numbered storyboard to the video model.',
          )
        }}
      </p>
      <div class="flex items-center gap-3">
        <Button as-child size="lg">
          <Link :href="account ? account.links.projects : register.url()">{{
            account ? $t('Open projects') : $t('Start a project')
          }}</Link>
        </Button>
      </div>
    </main>
  </div>
</template>
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import { login, register } from '@routes/public/auth'
import { Button } from '@shared:ui/button'

import { usePage } from '../../composables/page'

const { account, app } = usePage()
</script>
