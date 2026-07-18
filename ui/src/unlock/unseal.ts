// ct_sealed_v1 WebCrypto unseal — byte-compatible with the PHP seal
// (includes/class-crawlertoll-sealed.php) and the registry/SDK. AES-256-GCM,
// 32-byte CEK, 12-byte nonce, content_id as AAD, ciphertext = ct||tag (16B tag).
//
// Pure + importable so the cross-language parity test (PHP seal ↔ WebCrypto
// unseal) can exercise it directly under Node's native crypto.subtle.
//
// SECURITY: the CEK is imported NON-EXTRACTABLE so a compromised page (XSS)
// cannot exportKey the released key back out. decrypt() throws on a wrong CEK or
// a tampered AAD (GCM auth failure) — callers must treat a throw as "unlock
// failed" and never partial-render.

export interface SealedBlob {
  magic: string;
  content_id: string;
  alg?: string;
  nonce: string; // base64, 12 bytes
  ciphertext: string; // base64, ct||tag (16-byte tag appended)
}

function unb64(s: string): Uint8Array<ArrayBuffer> {
  const bin = atob(s);
  const buf = new ArrayBuffer(bin.length);
  const out = new Uint8Array(buf);
  for (let i = 0; i < bin.length; i++) {
    out[i] = bin.charCodeAt(i);
  }
  return out;
}

export async function unseal(blob: SealedBlob, cekB64: string): Promise<string> {
  if (!blob || blob.magic !== "ct_sealed_v1") {
    throw new Error("not a ct_sealed_v1 blob");
  }
  const key = await crypto.subtle.importKey(
    "raw",
    unb64(cekB64),
    "AES-GCM",
    false, // extractable = false — XSS cannot export the released CEK
    ["decrypt"],
  );
  const plain = await crypto.subtle.decrypt(
    {
      name: "AES-GCM",
      iv: unb64(blob.nonce),
      additionalData: new TextEncoder().encode(blob.content_id),
    },
    key,
    unb64(blob.ciphertext), // ct||tag straight in (do NOT split the tag — WebCrypto wants it appended)
  );
  return new TextDecoder().decode(new Uint8Array(plain));
}
