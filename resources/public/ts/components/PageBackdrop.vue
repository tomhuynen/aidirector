<template>
  <div
    aria-hidden="true"
    class="pointer-events-none fixed inset-x-0 top-14 bottom-0 -z-10 bg-cover bg-no-repeat"
    :class="fade === 'center' ? 'bg-center' : 'bg-right-bottom'"
    :style="{ backgroundImage: `url(${image})` }"
  >
    <div
      v-if="fade === 'center'"
      class="absolute inset-0 bg-gradient-to-r from-transparent via-background/60 to-transparent"
    />
    <div
      v-else
      class="absolute inset-0 bg-gradient-to-r from-background via-background/70 to-transparent lg:via-background/30"
    />
  </div>
</template>
<script setup lang="ts">
/**
 * A full-screen illustration behind a page, under the top bar. "left" keeps
 * the left side dark for a page's text; "center" darkens the middle band for
 * content that runs down the centre, with the illustration showing at the sides.
 */
withDefaults(
  defineProps<{
    image: string
    fade?: 'left' | 'center'
  }>(),
  { fade: 'left' },
)
</script>
