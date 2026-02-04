<template>
  <div class="relative">
    <div class="absolute inset-0 flex items-center" aria-hidden="true">
      <div class="w-full border-t" />
    </div>
    <div class="relative flex justify-center">
      <span class="px-2 text-sm text-muted-foreground bg-background">
        <slot />
      </span>
    </div>
  </div>
</template>
