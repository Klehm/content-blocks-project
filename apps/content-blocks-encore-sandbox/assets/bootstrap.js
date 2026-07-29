import { startStimulusApp } from '@symfony/stimulus-bridge';

// Registers the controllers declared in controllers.json plus anything under
// ./controllers/. Under AssetMapper this is `startStimulusApp()` from
// @symfony/stimulus-bundle instead — the controllers themselves are identical.
export const app = startStimulusApp(require.context(
    '@symfony/stimulus-bridge/lazy-controller-loader!./controllers',
    true,
    /\.[jt]sx?$/,
));

app.debug = false;
