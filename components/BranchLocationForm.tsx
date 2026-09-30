"use client";

import { useActionState, useState } from "react";
import { MapPin, Loader2, CheckCircle2, AlertCircle } from "lucide-react";
import { setBranchLocation, type SetBranchLocationState } from "@/lib/actions/settings";
import { ConfirmSubmitButton } from "@/components/ConfirmSubmitButton";

const initialState: SetBranchLocationState = {};

export function BranchLocationForm({
  branch,
}: {
  branch: { id: string; name: string; latitude: number | null; longitude: number | null; geofenceRadiusMeters: number };
}) {
  const [state, formAction, pending] = useActionState(setBranchLocation, initialState);
  const [coords, setCoords] = useState<{ lat: number; lng: number; accuracy: number } | null>(
    branch.latitude != null && branch.longitude != null ? { lat: branch.latitude, lng: branch.longitude, accuracy: 0 } : null
  );
  const [locating, setLocating] = useState(false);
  const [locError, setLocError] = useState<string | null>(null);

  function useCurrentLocation() {
    setLocError(null);
    if (!("geolocation" in navigator)) {
      setLocError("Your browser doesn't support location.");
      return;
    }
    setLocating(true);
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        setLocating(false);
        setCoords({ lat: pos.coords.latitude, lng: pos.coords.longitude, accuracy: Math.round(pos.coords.accuracy) });
      },
      () => {
        setLocating(false);
        setLocError("Couldn't get your location — make sure location access is allowed for this site, then try again.");
      },
      { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
  }

  return (
    <div className="mt-2 space-y-2 w-64">
      <form action={formAction} className="space-y-2">
        <input type="hidden" name="branchId" value={branch.id} />
        <input type="hidden" name="latitude" value={coords?.lat ?? ""} />
        <input type="hidden" name="longitude" value={coords?.lng ?? ""} />

        <button
          type="button"
          onClick={useCurrentLocation}
          disabled={locating}
          className="btn btn-secondary w-full justify-center text-sm disabled:opacity-70"
        >
          {locating ? <Loader2 size={14} className="animate-spin" /> : <MapPin size={14} />}
          {locating ? "Getting location…" : "Use my current location"}
        </button>

        {coords && (
          <p className="text-[11px] text-muted flex items-center gap-1">
            <CheckCircle2 size={11} className="text-success shrink-0" /> Captured: {coords.lat.toFixed(5)}, {coords.lng.toFixed(5)}
            {coords.accuracy > 0 && ` (±${coords.accuracy}m accuracy)`}
          </p>
        )}
        {locError && (
          <p className="text-[11px] text-danger flex items-center gap-1"><AlertCircle size={11} className="shrink-0" /> {locError}</p>
        )}

        <input name="radius" type="number" min={1} defaultValue={branch.geofenceRadiusMeters} placeholder="Radius (meters)" className="input text-sm" />

        <button type="submit" disabled={pending || !coords} className="btn btn-primary w-full justify-center text-sm disabled:opacity-50" title={!coords ? "Capture a location first" : undefined}>
          {pending ? "Saving…" : "Save"}
        </button>

        {state.error && <p className="text-[11px] text-danger flex items-center gap-1"><AlertCircle size={11} className="shrink-0" /> {state.error}</p>}
        {state.success && <p className="text-[11px] text-success flex items-center gap-1"><CheckCircle2 size={11} className="shrink-0" /> Saved</p>}
      </form>

      {branch.latitude != null && (
        <form action={formAction}>
          <input type="hidden" name="branchId" value={branch.id} />
          <input type="hidden" name="latitude" value="" />
          <input type="hidden" name="longitude" value="" />
          <ConfirmSubmitButton
            confirmMessage={`Remove the geofence for ${branch.name}? Check-in will no longer be location-restricted here.`}
            className="text-[11px] text-danger hover:underline w-full text-center block"
          >
            Remove geofence
          </ConfirmSubmitButton>
        </form>
      )}
    </div>
  );
}
