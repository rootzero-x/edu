import { appIcon } from "@/lib/appIcon";

export const size = { width: 180, height: 180 };
export const contentType = "image/png";
export const revalidate = 3600;

export default function AppleIcon() {
  return appIcon(180, true);
}
