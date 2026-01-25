import { useMemo, useEffect } from 'react'
import * as THREE from 'three'

const DOME_RADIUS = 1400
const DISC_COLOR = new THREE.Color('#f5ede0')
const DISC_WARM_COLOR = new THREE.Color('#f1d1a3')
const BULGE_COLOR = new THREE.Color('#f7d7aa')
const CORE_DIRECTION = new THREE.Vector3(0, 1, 0)

function createDiscMaterial() {
  return new THREE.ShaderMaterial({
    transparent: true,
    depthWrite: false,
    depthTest: false,
    blending: THREE.AdditiveBlending,
    side: THREE.BackSide,
    toneMapped: false,
    uniforms: {
      uColor: { value: DISC_COLOR.clone() },
      uWarmColor: { value: DISC_WARM_COLOR.clone() },
      uCoreDirection: { value: CORE_DIRECTION.clone() },
  uBandStrength: { value: 0.22 },
  uLatitudeFalloff: { value: 0.14 },
      uRimSoftness: { value: 0.25 },
      uMottleScale: { value: 40 },
      uMottleStrength: { value: 0.08 },
    },
    vertexShader: /* glsl */`
      varying vec3 vDirection;
      void main() {
        vec4 worldPosition = modelMatrix * vec4(position, 1.0);
        vDirection = normalize(worldPosition.xyz);
        gl_Position = projectionMatrix * viewMatrix * worldPosition;
      }
    `,
    fragmentShader: /* glsl */`
      varying vec3 vDirection;
    uniform vec3 uColor;
    uniform vec3 uWarmColor;
    uniform float uBandStrength;
    uniform vec3 uCoreDirection;
      uniform float uLatitudeFalloff;
      uniform float uRimSoftness;
      uniform float uMottleScale;
      uniform float uMottleStrength;

      float hash(vec3 p) {
        return fract(sin(dot(p, vec3(127.1, 311.7, 74.7))) * 43758.5453);
      }

      float noise(vec3 p) {
        vec3 i = floor(p);
        vec3 f = fract(p);
        f = f * f * (3.0 - 2.0 * f);

        float n000 = hash(i + vec3(0.0, 0.0, 0.0));
        float n100 = hash(i + vec3(1.0, 0.0, 0.0));
        float n010 = hash(i + vec3(0.0, 1.0, 0.0));
        float n110 = hash(i + vec3(1.0, 1.0, 0.0));
        float n001 = hash(i + vec3(0.0, 0.0, 1.0));
        float n101 = hash(i + vec3(1.0, 0.0, 1.0));
        float n011 = hash(i + vec3(0.0, 1.0, 1.0));
        float n111 = hash(i + vec3(1.0, 1.0, 1.0));

        float nx00 = mix(n000, n100, f.x);
        float nx10 = mix(n010, n110, f.x);
        float nx01 = mix(n001, n101, f.x);
        float nx11 = mix(n011, n111, f.x);

        float nxy0 = mix(nx00, nx10, f.y);
        float nxy1 = mix(nx01, nx11, f.y);

        return mix(nxy0, nxy1, f.z);
      }

      void main() {
        float latitude = abs(vDirection.z);
        float planarLength = length(vDirection.xy);
        vec2 normalizedCore = normalize(uCoreDirection.xy);
    vec2 planarDir = planarLength > 0.0001 ? normalize(vDirection.xy) : normalizedCore;
    float alignment = clamp(dot(planarDir, normalizedCore), 0.0, 1.0);
    float coreSpan = smoothstep(0.93, 0.999, alignment);
    float bulgeMask = pow(coreSpan, 3.0);
    float widthScale = mix(0.36, 0.78, bulgeMask);
        float band = exp(-pow(latitude / (uLatitudeFalloff * widthScale + 0.0001), 2.0));
        float rim = smoothstep(uRimSoftness, 1.0, length(vDirection.xy));
        float dust = noise(vDirection * uMottleScale);
        float mottling = mix(1.0 - uMottleStrength, 1.0 + uMottleStrength, dust);
    float bulgeGlow = mix(1.0, 1.35, bulgeMask);
    float bandLift = mix(1.1, 0.92, bulgeMask);
  float laneShadow = mix(0.6, 1.0, smoothstep(0.0, 0.04, latitude));
    float alpha = band * rim * uBandStrength * mottling * bulgeGlow * bandLift * laneShadow;
        if (alpha <= 0.0008) discard;
        float heightBias = smoothstep(0.02, 0.4, latitude);
        float tintBias = mix(0.08, 0.85, pow(heightBias, 0.65));
  tintBias = clamp(tintBias + bulgeMask * 0.05, 0.0, 1.0);
        vec3 tinted = mix(uColor, uWarmColor, tintBias);
        gl_FragColor = vec4(tinted, alpha);
      }
    `,
  })
}

function createBulgeMaterial() {
  return new THREE.ShaderMaterial({
    transparent: true,
    depthWrite: false,
    depthTest: false,
    blending: THREE.AdditiveBlending,
    toneMapped: false,
    uniforms: {
  uColor: { value: BULGE_COLOR.clone() },
  uIntensity: { value: 0.32 },
    },
    vertexShader: /* glsl */`
      varying vec3 vWorldPosition;
      void main() {
        vec4 worldPosition = modelMatrix * vec4(position, 1.0);
        vWorldPosition = worldPosition.xyz;
        gl_Position = projectionMatrix * viewMatrix * worldPosition;
      }
    `,
    fragmentShader: /* glsl */`
      varying vec3 vWorldPosition;
      uniform vec3 uColor;
      uniform float uIntensity;

      void main() {
        float radial = length(vWorldPosition.xy) / 55.0;
        float vertical = abs(vWorldPosition.z) / 22.0;
        float density = exp(-radial * radial * 1.8) * exp(-vertical * 3.8);
        float alpha = density * uIntensity;
        if (alpha <= 0.0015) discard;
        gl_FragColor = vec4(uColor, alpha);
      }
    `,
  })
}

export default function MilkyWayGlow() {
  const domeGeometry = useMemo(() => new THREE.SphereGeometry(DOME_RADIUS, 64, 64), [])
  const domeMaterial = useMemo(() => createDiscMaterial(), [])

  const bulgeGeometry = useMemo(() => new THREE.SphereGeometry(26, 64, 64), [])
  const bulgeMaterial = useMemo(() => createBulgeMaterial(), [])

  useEffect(() => {
    return () => {
      domeGeometry.dispose()
      domeMaterial.dispose()
      bulgeGeometry.dispose()
      bulgeMaterial.dispose()
    }
  }, [domeGeometry, domeMaterial, bulgeGeometry, bulgeMaterial])

  return (
    <group name="MilkyWayGlow">
      <mesh
        geometry={domeGeometry}
        material={domeMaterial}
        frustumCulled={false}
        renderOrder={-6}
      />
      <mesh
        geometry={bulgeGeometry}
        material={bulgeMaterial}
        scale={[34, 34, 24]}
        renderOrder={-4}
      />
    </group>
  )
}
