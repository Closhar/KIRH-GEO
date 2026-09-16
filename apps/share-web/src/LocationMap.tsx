import { useEffect, useRef, useState } from 'react';
import * as maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import type { SharedPoint } from './share-session';

export default function LocationMap({ point, styleUrl }: { point: SharedPoint; styleUrl: string }) {
  const container = useRef<HTMLDivElement>(null);
  const map = useRef<maplibregl.Map | null>(null);
  const marker = useRef<maplibregl.Marker | null>(null);
  const [error, setError] = useState(false);
  useEffect(() => {
    if (!container.current) return;
    const style = new URL(styleUrl, window.location.origin);
    if (style.protocol !== 'https:' && style.origin !== window.location.origin) {
      setError(true);
      return;
    }
    try {
      const instance = new maplibregl.Map({
        container: container.current, style: style.toString(), center: [point.longitude, point.latitude], zoom: 14,
        attributionControl: { compact: true },
        // The map receives neither the original share secret nor its limited API bearer.
        transformRequest: (url) => ({ url, credentials: 'same-origin', headers: {} }),
      });
      map.current = instance;
      marker.current = new maplibregl.Marker({ color: '#56368e' }).setLngLat([point.longitude, point.latitude]).addTo(instance);
      instance.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
      instance.on('error', () => setError(true));
      return () => { marker.current?.remove(); instance.remove(); map.current = null; };
    } catch { setError(true); }
  }, [styleUrl]);
  useEffect(() => {
    marker.current?.setLngLat([point.longitude, point.latitude]);
    map.current?.easeTo({ center: [point.longitude, point.latitude], duration: 500 });
  }, [point.longitude, point.latitude]);
  return <><div className="map" ref={container} aria-label="Карта текущей геопозиции" />{error && <div className="map-error" role="status">Карта не загрузилась. Координаты: {point.latitude.toFixed(6)}, {point.longitude.toFixed(6)}</div>}</>;
}
