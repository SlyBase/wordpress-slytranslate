kubectl apply -f ./mariaDB.secrets.yaml &&
helm install wp-test-translatepress oci://ghcr.io/slybase/charts/wordpress --values ./translatepress.yaml