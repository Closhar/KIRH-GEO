import { takeFragmentToken } from './share-session';
import {takeIdentityAction} from './action-fragment';
import './style.css';

const identityAction = takeIdentityAction(window.location, window.history);
if (identityAction) {
  // Import UI only after the one-time secret has left the address bar.
  void import('./recovery').then(({renderRecovery}) => renderRecovery(identityAction.purpose, identityAction.token));
} else {
  const token = takeFragmentToken(window.location, window.history);
  // Import rendering/maps only after the original secret has left the address bar.
  void import('./render').then(({ renderApp }) => renderApp(token));
}
