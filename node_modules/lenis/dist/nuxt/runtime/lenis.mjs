// packages/vue/nuxt/runtime/lenis.ts
import { defineNuxtPlugin } from "#imports";
import vuePlugin from "lenis/vue";
var plugin = defineNuxtPlugin({
  name: "lenis",
  setup(nuxtApp) {
    nuxtApp.vueApp.use(vuePlugin);
  }
});
var lenis_default = plugin;
export {
  lenis_default as default
};
