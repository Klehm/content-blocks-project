// The default theme, plus the styles of the screenshots and the home page.
import DefaultTheme from 'vitepress/theme';
import KitGallery from './KitGallery.vue';
import './custom.css';

export default {
  extends: DefaultTheme,
  enhanceApp({ app }) {
    app.component('KitGallery', KitGallery);
  },
};
