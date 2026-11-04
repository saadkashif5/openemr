# Site template

The files every new practice starts with. `provision-practice.php` copies this directory to
`sites/<domain>/` and then writes that practice's own `sqlconf.php`.

- Built from the files **committed** under `sites/default` (`git archive`), never from a live site,
  so uploaded documents, keys, logos or settings from a real practice cannot leak into a new one.
- Deliberately has **no `sqlconf.php`**: that keeps it from being served as a site, and it holds no
  database credentials. The provisioner refuses to use a template that contains one.
- `docker-version` is left out: it belongs to the Docker image's upgrade logic for the default site.

Change what new practices start with by editing files here and committing them. Per-specialty
differences belong in `templates/<specialty>/site/`, applied on top of this.
