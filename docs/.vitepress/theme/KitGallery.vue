<script setup>
// Every kit block, as rendered by kit.css, in the sidebar's order.
import { withBase } from 'vitepress';
import nav from '../data/blocks-nav.json';

const blocks = nav.map(({ text, link }) => {
  const [type, label] = text.split(' — ');
  return { type, label, link };
});
</script>

<template>
  <div class="cb-kit-grid">
    <a v-for="b in blocks" :key="b.type" :href="withBase(b.link)">
      <span class="cb-kit-thumb">
        <img :src="withBase(`/screenshots/kit/thumbs/${b.type}.webp`)"
             :alt="`The ${b.type} block`" loading="lazy">
      </span>
      <span class="cb-kit-name">{{ b.label }} <code>{{ b.type }}</code></span>
    </a>
  </div>
</template>
