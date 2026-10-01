<template>
  <div
    aria-hidden="true"
    class="pointer-events-none fixed inset-x-0 top-14 bottom-0 -z-10 bg-cover bg-right-bottom bg-no-repeat"
    :style="{ backgroundImage: `url(${image})` }"
  >
    <div
      class="absolute inset-0 bg-gradient-to-r from-background via-background/70 to-transparent lg:via-background/30"
    />
  </div>
</template>
<script setup lang="ts">
/**
 * A full-screen illustration behind a page, under the top bar, fading to the
 * page background on the left so the page's text stays readable.
 */
defineProps<{
  image: string
}>()
</script>
