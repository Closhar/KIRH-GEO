import * as TaskManager from 'expo-task-manager';
import type {LocationObject} from 'expo-location';
import {LOCATION_TASK, receiveLocations} from './engine';

TaskManager.defineTask<{locations: LocationObject[]}>(LOCATION_TASK, async ({data, error}) => {
  if (error || !data) return;
  try {await receiveLocations(data.locations);}
  catch { /* Native task retries on the next delivery; never log coordinates or device secrets. */ }
});
