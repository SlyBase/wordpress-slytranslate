kubectl apply -f ./mariaDB.secrets.yaml &&
helm install wp-test-wpglobus  oci://ghcr.io/slybase/charts/wordpress --values ./wpglobus.yaml