SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
kubectl apply -f "$SCRIPT_DIR/mariaDB.secrets.yaml" &&
helm install wp-test-wpglobus  oci://ghcr.io/slybase/charts/wordpress --values "$SCRIPT_DIR/wpglobus.yaml"