import { api } from './api';

export async function getLauncherApps() {
  return api.get('v1/auth/controller-apps/launcher');
}

export async function getSsoLaunchUrl(appCode) {
  return api.get('v1/auth/sso/launch-url', { app_code: appCode });
}
