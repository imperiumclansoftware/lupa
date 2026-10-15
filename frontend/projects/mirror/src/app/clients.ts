import { EcosystemId } from '@sas/shared';

/** Adresse du miroir telle que vue par ce navigateur (les postes clients du réseau isolé utilisent la même). */
export function mirrorBase(baseUrl?: string): { base: string; host: string; http: boolean } {
  const base = (baseUrl || window.location.origin).replace(/\/$/, '');
  const url = new URL(base);
  return { base, host: url.host, http: url.protocol === 'http:' };
}

export function installCommand(ecosystem: EcosystemId, name: string, version: string, baseUrl?: string): string {
  const { host } = mirrorBase(baseUrl);
  switch (ecosystem) {
    case 'npm':
      return `npm install ${name}@${version}`;
    case 'pypi':
      return `pip install ${name}==${version}`;
    case 'composer':
      return `composer require ${name}:${version}`;
    case 'docker': {
      const short = name.startsWith('library/') ? name.slice(8) : name;
      return version.startsWith('sha256:') ? `docker pull ${host}/${short}@${version}` : `docker pull ${host}/${short}:${version}`;
    }
    case 'galaxy':
      return `ansible-galaxy collection install '${name}:==${version}'`;
    case 'terraform': {
      if (name === 'terraform') return `curl -O ${mirrorBase(baseUrl).base}/terraform/releases/terraform_${version}_linux_amd64.zip`;
      const parts = name.split('/');
      if (parts.length === 3 && !parts[0].includes('.')) return `source = "${mirrorBase(baseUrl).base}/terraform/modules/${name}/${version}.tar.gz"`;
      const short = parts.slice(-2).join('/');
      return `${parts.at(-1)} = { source = "${short}", version = "${version}" }`;
    }
    case 'deb':
      return `sudo apt install ${name}=${version}`;
    case 'rpm':
      return `sudo dnf install ${name}-${version}`;
  }
}
