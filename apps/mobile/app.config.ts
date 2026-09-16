import type {ConfigContext, ExpoConfig} from 'expo/config';

export default ({config}: ConfigContext): ExpoConfig => ({
  ...config,
  name: config.name ?? 'KIRH GEO',
  slug: config.slug ?? 'kirh-geo',
  android: {
    ...config.android,
    ...(process.env.GOOGLE_SERVICES_JSON ? {googleServicesFile: process.env.GOOGLE_SERVICES_JSON} : {}),
    config: {...config.android?.config, ...(process.env.ANDROID_MAPS_API_KEY ? {googleMaps: {apiKey: process.env.ANDROID_MAPS_API_KEY}} : {})},
  },
});
